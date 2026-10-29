<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next number for a scope, in the caller's transaction (ADR-0002, §7.2 amended).
 *
 * Three steps, in order:
 *
 *  1. `SELECT ... FOR UPDATE` the pre-existing sequence row — this row lock is what serialises
 *     concurrent issuers.
 *  2. Increment transactionally, so a rollback releases the lock *and* un-burns the value.
 *  3. Render from the format and hand the string back for the document to carry.
 *
 * There is deliberately no INSERT here. §7.2 originally said "INSERT IGNORE the row first", but
 * measured on MySQL 9.1 that is a deadlock: an INSERT IGNORE that finds the row already there
 * takes a shared next-key lock, so step 1 becomes a shared-to-exclusive upgrade, and four
 * concurrent workers deadlocked on it. The rows are created ahead of time by
 * {@see NumberSequenceRegistry} instead, so this path takes exactly one lock. See §20.4.
 *
 * Callers must not do I/O while holding it (no mail, PDF render or queue dispatch inside the
 * transaction) — see the afterCommit rule in §7.4. Because a lock wait can still end in a
 * serialization failure under load, the enclosing Action should be wrapped in
 * `DB::transaction($fn, attempts: 3)` so the *whole* business transaction retries.
 */
final class NumberAllocator
{
    /**
     * @param  DateTimeInterface|CarbonImmutable|null  $on  the date that decides the period; defaults to now
     * @param  int|null  $usedBy  user id recorded on the sequence row for the audit trail
     * @return string the rendered document number, e.g. INV-FY26/27-0000042
     */
    public function allocate(string $scope, DateTimeInterface|CarbonImmutable|null $on = null, ?int $usedBy = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw NumberingError::outsideTransaction($scope);
        }

        $on = Carbon::instance($on ?? now());
        $definition = $this->definition($scope);
        $periodKey = NumberPeriod::keyFor($definition['restart'], $on, $scope);
        $companyId = CurrentCompany::id();

        $row = DB::table('number_sequences')
            ->where('company_id', $companyId)
            ->where('scope', $scope)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw NumberingError::rowMissing($scope, $periodKey);
        }

        $value = (int) $row->next_value;

        DB::table('number_sequences')
            ->where('id', $row->id)
            ->update([
                'next_value' => $value + 1,
                'last_used_at' => now(),
                'last_used_by' => $usedBy,
            ]);

        return NumberFormat::render($definition['format'], $periodKey, $value);
    }

    /**
     * The number the *next* allocation in this period would receive, without locking or
     * incrementing. Used by the integrity checker and by a "next number" hint on a screen that
     * must not burn a value just to preview it.
     */
    public function peek(string $scope, DateTimeInterface|CarbonImmutable|null $on = null): ?int
    {
        $definition = $this->definition($scope);
        $periodKey = NumberPeriod::keyFor($definition['restart'], Carbon::instance($on ?? now()), $scope);

        $value = DB::table('number_sequences')
            ->where('company_id', CurrentCompany::id())
            ->where('scope', $scope)
            ->where('period_key', $periodKey)
            ->value('next_value');

        return $value === null ? null : (int) $value;
    }

    /**
     * Read from config, but validated rather than trusted: a typo in numbering.scopes would
     * otherwise surface as a confusing allocation error at the moment a fiscal document is issued.
     *
     * @return array{format: string, restart: string, fiscal: bool}
     */
    private function definition(string $scope): array
    {
        $raw = config("fleet.numbering.scopes.{$scope}");

        if (! is_array($raw)) {
            throw NumberingError::unknownScope($scope);
        }

        $format = (string) ($raw['format'] ?? '');
        $restart = (string) ($raw['restart'] ?? '');
        $fiscal = (bool) ($raw['fiscal'] ?? false);

        NumberFormat::validate($format);

        if (! in_array($restart, [NumberPeriod::Continuous, NumberPeriod::CalendarYear, NumberPeriod::FinancialYear], true)) {
            throw NumberingError::unknownRestartPolicy($scope, $restart);
        }

        return ['format' => $format, 'restart' => $restart, 'fiscal' => $fiscal];
    }
}
