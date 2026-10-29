<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates the `number_sequences` rows a company needs, ahead of the first allocation.
 *
 * This exists because DESIGN.md §7.2 step 1 was wrong, and the 3307 instance proved it:
 * `INSERT IGNORE` on a row that already exists takes a shared next-key lock on it, so the
 * `SELECT ... FOR UPDATE` that follows is a shared-to-exclusive lock upgrade. Two concurrent
 * issuers both holding S and both asking for X is the textbook InnoDB deadlock, and it
 * reproduced on the first four-process run. §7.2 is amended in §20.4: the row is created
 * outside the allocation path, so allocation only ever takes one X lock and serialises cleanly.
 *
 * Rows must exist before their period starts — hence a seeder at install and a scheduled
 * command across the year boundary, not an on-demand insert inside a business transaction.
 */
final class NumberSequenceRegistry
{
    /**
     * Idempotent. Safe to run every night.
     *
     * @return list<string> "scope:period_key" pairs that were created by this call
     */
    public function ensureAll(DateTimeInterface|CarbonImmutable|null $on = null): array
    {
        if (DB::transactionLevel() > 0) {
            throw new \RuntimeException(
                'ensureAll() must not run inside a business transaction — it commits sequence rows '
                .'that other issuers need to see before they take their lock.'
            );
        }

        $created = [];

        foreach ($this->definitions() as $scope => $definition) {
            // Validate before persisting: a bad format written into number_sequences would only
            // surface at the moment someone issues a fiscal document, and then as a render failure.
            NumberFormat::validate($definition['format']);

            $periodKey = NumberPeriod::keyFor($definition['restart'], Carbon::instance($on ?? now()), $scope);

            if ($this->ensure($scope, $definition['format'], $periodKey)) {
                $created[] = "{$scope}:{$periodKey}";
            }
        }

        return $created;
    }

    public function ensure(string $scope, string $format, string $periodKey): bool
    {
        $companyId = CurrentCompany::id();

        $exists = DB::table('number_sequences')
            ->where('company_id', $companyId)
            ->where('scope', $scope)
            ->where('period_key', $periodKey)
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('number_sequences')->insert([
            'company_id' => $companyId,
            'scope' => $scope,
            'period_key' => $periodKey,
            'next_value' => 1,
            'format' => $format,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** @return array<string, array{format: string, restart: string, fiscal: bool}> */
    private function definitions(): array
    {
        /** @var array<string, array{format: string, restart: string, fiscal: bool}> $scopes */
        $scopes = config('fleet.numbering.scopes', []);

        return $scopes;
    }
}
