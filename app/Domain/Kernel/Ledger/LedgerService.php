<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger;

use App\Domain\Kernel\Ledger\Data\AccountTurnover;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\JournalLine;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only write path into the ledger (§6.3 layer 1, ADR-0001 #4).
 *
 * Everything a posting must get right lives here so that no module can get it wrong
 * independently: the balance arithmetic, the account's postable/active flags, the
 * `allowed_dimension_mask` assertion, the period resolution, the gap-free entry number and the
 * replay guard. Below this class the database refuses what this class missed (§6.3 layers 2–4).
 *
 * The three-step write order — draft entry, lines, then the draft → posted flip — is not
 * incidental: the `gl_journal_lines_bi` trigger only accepts a line whose entry is still draft,
 * and the posted CHECKs only become true at the moment the totals land. Reversing the order makes
 * the entry impossible to post.
 *
 * Retries: three, because allocation takes a row lock (ADR-0002, §20.4) and a lock wait can end
 * in a serialization failure under real concurrency. The whole business transaction retries, not
 * the allocation alone, so a retry never posts half an entry.
 */
final class LedgerService
{
    /**
     * The §4.2 typed dimension columns a total may be narrowed by. A whitelist rather than trusting
     * the caller's string: the column name is interpolated into the query, and the ledger's own
     * columns are the only words that may appear there.
     */
    private const GROUPABLE_DIMENSIONS = [
        'truck_id', 'trailer_id', 'trip_id', 'job_id', 'customer_id', 'supplier_id', 'driver_id',
        'employee_id', 'work_order_id', 'invoice_id', 'bill_id', 'fuel_issue_id',
        'border_crossing_id', 'country_code', 'cost_centre_id',
    ];

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly PeriodResolver $periods,
    ) {}

    public function post(PostingDto $posting): JournalEntry
    {
        $existing = $this->findPosted($posting);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertBalanced($posting);
        $accounts = $this->resolveAccounts($posting);
        $this->assertDimensions($posting, $accounts);
        $this->assertCurrency($posting);

        $period = $this->periods->for($posting->postedOn);

        if ($period->isLocked()) {
            throw PostingRejected::periodIsLocked($period->period);
        }

        try {
            return DB::transaction(function () use ($posting, $period): JournalEntry {
                $totals = $posting->totals();
                $postedOn = Carbon::instance($posting->postedOn);

                $entry = JournalEntry::create([
                    'id' => (string) Str::ulid(),
                    'entry_no' => $this->numbers->allocate('journal_entry', $postedOn, $posting->createdBy),
                    'posted_at' => Carbon::now(),
                    'posted_on' => $postedOn->toDateString(),
                    'period_id' => $period->id,
                    'status' => JournalEntry::Draft,
                    'description' => $posting->description,
                    'currency_code' => $posting->currencyCode,
                    'fx_rate' => $posting->fxRate,
                    'fx_rate_source' => $posting->fxRateSource->value,
                    'fx_rate_date' => Carbon::instance($posting->fxRateDate)->toDateString(),
                    'fx_rate_ref' => $posting->fxRateRef,
                    'base_currency_code' => CurrentCompany::model()->base_currency_code,
                    'source_type' => $posting->sourceType,
                    'source_id' => $posting->sourceId,
                    'source_event_id' => $posting->sourceEventId,
                    'reverses_entry_id' => $posting->reversesEntryId,
                    'netting_group' => $posting->nettingGroup,
                    'created_by' => $posting->createdBy,
                ]);

                foreach ($posting->lines as $line) {
                    $this->appendLine($entry, $line);
                }

                // The draft -> posted flip is the single legal update (§6.3), and the totals are
                // assigned rather than mass-filled: they are the poster's computed values.
                $entry->status = JournalEntry::Posted;
                $entry->total_debit = $totals['debit'];
                $entry->total_credit = $totals['credit'];
                $entry->line_count = $posting->lineCount();
                $entry->save();

                return $entry;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Two deliveries of the same event can race past the pre-check above; the unique
            // index (ADR-0001 #3) is the real guard, so the loser returns the winner's entry
            // instead of failing a queued job forever with a duplicate-key error.
            if ($winner = $this->findPosted($posting)) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * ADR-0001 #3: the source triple is the idempotency key, so a redelivered event returns the
     * entry it already created instead of creating a second one.
     */
    public function findPosted(PostingDto $posting): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $posting->sourceType)
            ->where('source_id', $posting->sourceId)
            ->where('source_event_id', $posting->sourceEventId)
            ->first();
    }

    /**
     * §6.6's read side: base amounts summed per account and side for the lines carrying these
     * dimensions. The rollup that keeps a trip's margin honest reads through this method rather
     * than the tables (D3), so "derive from the ledger" stays one door in both directions.
     *
     * Narrowing is mandatory. An unnarrowed sum is a trial balance, not a projection, and a caller
     * that asks for one has almost certainly forgotten the dimension it meant to filter on.
     *
     * @param  array<string, string|int>  $dimensions  column => value, from {@see self::GROUPABLE_DIMENSIONS}
     * @return list<AccountTurnover>
     */
    public function totalsByAccount(array $dimensions): array
    {
        if ($dimensions === []) {
            throw new InvalidArgumentException('A ledger total must be narrowed by at least one dimension (§4.2); an unnarrowed sum is a report, not a rollup.');
        }

        $query = JournalLine::query()
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.account_id')
            ->groupBy('gl_accounts.code', 'gl_journal_lines.direction')
            ->selectRaw('gl_accounts.code as account_code, gl_journal_lines.direction as direction, sum(gl_journal_lines.amount_base) as total');

        foreach ($dimensions as $column => $value) {
            if (! in_array($column, self::GROUPABLE_DIMENSIONS, true)) {
                throw new InvalidArgumentException("`{$column}` is not a typed ledger dimension a total may be narrowed by (§4.2).");
            }

            $query->where("gl_journal_lines.{$column}", $value);
        }

        return $query->get()
            ->map(static fn (JournalLine $row): AccountTurnover => new AccountTurnover(
                (string) $row->account_code,
                $row->direction instanceof Side ? $row->direction : Side::from((string) $row->direction),
                (int) $row->total,
            ))
            ->all();
    }

    private function assertBalanced(PostingDto $posting): void
    {
        if ($posting->lineCount() === 0) {
            throw PostingRejected::noLines();
        }

        $debit = false;
        $credit = false;

        foreach ($posting->lines as $line) {
            if ($line->base->minorUnits === 0) {
                throw PostingRejected::zeroAmount();
            }

            if ($line->side === Side::Debit) {
                $debit = true;
            } else {
                $credit = true;
            }
        }

        if (! $debit || ! $credit) {
            throw PostingRejected::oneSided();
        }

        $totals = $posting->totals();

        if ($totals['debit'] !== $totals['credit'] || $totals['debit'] === 0) {
            throw PostingRejected::unbalanced(
                $totals['debit'],
                $totals['credit'],
                CurrentCompany::model()->base_currency_code,
            );
        }
    }

    /** @return array<int, Account> keyed by account id */
    private function resolveAccounts(PostingDto $posting): array
    {
        $ids = array_values(array_unique(array_map(fn (LineDto $l): int => $l->accountId, $posting->lines)));

        /** @var array<int, Account> $accounts */
        $accounts = Account::query()->whereIn('id', $ids)->get()->keyBy('id')->all();

        foreach ($posting->lines as $line) {
            $account = $accounts[$line->accountId] ?? null;

            if ($account === null) {
                throw PostingRejected::accountMissing($line->accountId);
            }

            if (! $account->is_postable) {
                throw PostingRejected::accountNotPostable($account->code, $account->name);
            }

            if (! $account->is_active) {
                throw PostingRejected::accountInactive($account->code);
            }
        }

        return $accounts;
    }

    /**
     * §6.1: a dimension bit on an account is a requirement, so a line that reaches this account
     * without it is a posting bug — the fuel line that lost its truck and landed in overheads.
     *
     * @param  array<int, Account>  $accounts
     */
    private function assertDimensions(PostingDto $posting, array $accounts): void
    {
        foreach ($posting->lines as $line) {
            $account = $accounts[$line->accountId];

            foreach ($account->requiredDimensions() as $dimension) {
                $value = $line->dimensionValue($dimension->column());

                if ($value === null || $value === '') {
                    throw PostingRejected::dimensionRequired($account->code, $dimension->name);
                }
            }
        }
    }

    private function assertCurrency(PostingDto $posting): void
    {
        $base = CurrentCompany::model()->base_currency_code;

        foreach ($posting->lines as $line) {
            if ($line->base->currencyCode !== $base) {
                throw PostingRejected::mixedBaseCurrency($base, $line->base->currencyCode);
            }

            if ($line->foreign !== null && $line->foreign->currencyCode !== $posting->currencyCode) {
                throw PostingRejected::currencyMismatch($line->foreign->currencyCode, $posting->currencyCode);
            }
        }
    }

    private function appendLine(JournalEntry $entry, LineDto $line): void
    {
        // Typed dimensions become their own columns (§6.2) — that is what makes "profit per
        // truck" a GROUP BY rather than a pivot.
        JournalLine::create(array_merge($line->dimensions, [
            'entry_id' => $entry->id,
            'account_id' => $line->accountId,
            'direction' => $line->side->value,
            'amount_foreign' => $line->foreign?->minorUnits,
            'amount_base' => $line->base->minorUnits,
            'posted_on' => Carbon::instance($entry->posted_on)->toDateString(),
            // A line without a foreign amount was entered in the base currency, so its own
            // currency is the entry's — which equals base here (§6.1: the FX block is on the entry).
            'currency_code' => $line->foreign === null ? $entry->currency_code : $line->foreign->currencyCode,
            'line_note' => $line->note,
            'dimension_extra' => $line->dimensionExtra === [] ? null : $line->dimensionExtra,
            'created_at' => Carbon::now(),
        ]));
    }
}
