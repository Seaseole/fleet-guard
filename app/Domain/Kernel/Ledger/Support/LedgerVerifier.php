<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Support;

use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the ledger from its own lines and reports every place the header row disagrees
 * (layer 2's "trusted but verified", layer 5's operational control).
 *
 * This exists because the denormalised `total_debit`/`total_credit`/`line_count` are what reports
 * read, and a CHECK constraint cannot aggregate across rows. Drift is therefore possible in a way
 * no constraint can catch — so the answer is a sweep that runs in the scheduler and raises an
 * alert rather than a discrepancy someone finds at year-end.
 *
 * Reads across companies deliberately ({@see BelongsToCompany::scopeWithoutCompanyScope()} is the
 * sanctioned exception): a verifier that only saw the tenant it happened to be running for would
 * miss exactly the cross-tenant posting bug it is here to find.
 */
final class LedgerVerifier
{
    /**
     * @return list<array{entry: string, entry_no: string, company?: int, finding: string}>
     */
    public function findings(): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
            select e.id, e.entry_no, e.company_id, e.status, e.base_currency_code,
                   e.total_debit, e.total_credit, e.line_count,
                   e.posted_on, e.period_id, p.starts_on, p.ends_on,
                   coalesce(sum(l.amount_base), 0) as real_amount,
                   coalesce(sum(case when l.direction = 'debit' then l.amount_base else 0 end), 0) as real_debit,
                   coalesce(sum(case when l.direction = 'credit' then l.amount_base else 0 end), 0) as real_credit,
                   count(l.id) as real_lines
            from gl_journal_entries e
            left join gl_journal_lines l on l.entry_id = e.id
            left join gl_periods p on p.id = e.period_id
            group by e.id, e.entry_no, e.company_id, e.status, e.base_currency_code,
                     e.total_debit, e.total_credit, e.line_count,
                     e.posted_on, e.period_id, p.starts_on, p.ends_on
            SQL);

        foreach ($rows as $row) {
            // Each finding names the company it belongs to, because the design turns it into an alert row
            // whose company_id is NOT NULL: drift in one tenant must never be reported to another.
            $label = ['entry' => (string) $row->id, 'entry_no' => (string) $row->entry_no, 'company' => (int) $row->company_id];

            if ($row->status !== JournalEntry::Posted) {
                continue;
            }

            // The totals below are base-currency minor units. The FK on base_currency_code
            // guarantees the row resolves, so the formatting cannot add a new failure mode.
            $currency = (string) $row->base_currency_code;

            // 1. The header totals are what every report reads. If they are not the lines, the
            //    reports are wrong even though the lines are right.
            if ((int) $row->real_debit !== (int) $row->total_debit) {
                $findings[] = $label + ['finding' => sprintf(
                    'total_debit is %s but the lines sum to %s',
                    $this->money((int) $row->total_debit, $currency),
                    $this->money((int) $row->real_debit, $currency),
                )];
            }

            if ((int) $row->real_credit !== (int) $row->total_credit) {
                $findings[] = $label + ['finding' => sprintf(
                    'total_credit is %s but the lines sum to %s',
                    $this->money((int) $row->total_credit, $currency),
                    $this->money((int) $row->real_credit, $currency),
                )];
            }

            if ((int) $row->real_lines !== (int) $row->line_count) {
                $findings[] = $label + ['finding' => sprintf(
                    'line_count is %d but %d lines exist',
                    (int) $row->line_count,
                    (int) $row->real_lines,
                )];
            }

            // 2. A posted entry that does not balance is the headline failure — it means the
            //    CHECK was bypassed (a barrier window) or the row predates the constraint.
            if ((int) $row->real_debit !== (int) $row->real_credit) {
                $findings[] = $label + ['finding' => sprintf(
                    'lines are out of balance: debits %s, credits %s',
                    $this->money((int) $row->real_debit, $currency),
                    $this->money((int) $row->real_credit, $currency),
                )];
            }

            if ((int) $row->real_lines < 2) {
                $findings[] = $label + ['finding' => 'posted with fewer than two lines'];
            }

            // 3. The period is the lock. An entry dated outside its own period row would be
            //    excluded from a locked month's trial balance while still being counted elsewhere.
            if ($row->starts_on === null || $row->ends_on === null) {
                $findings[] = $label + ['finding' => 'names a period row that does not exist'];
            } elseif ($row->posted_on < $row->starts_on || $row->posted_on > $row->ends_on) {
                $findings[] = $label + ['finding' => sprintf(
                    'posted_on %s falls outside period %s..%s',
                    $row->posted_on,
                    $row->starts_on,
                    $row->ends_on,
                )];
            }
        }

        // 4. Orphans: a line whose entry is not there cannot be reached by the browser, so it is
        //    invisible money. The FK makes this near-impossible and the check costs one query.
        //    Grouped by company rather than counted across the table, because the design turns each row of
        //    this into an alert with a tenant on it.
        $orphanRows = DB::table('gl_journal_lines')
            ->leftJoin('gl_journal_entries', 'gl_journal_entries.id', '=', 'gl_journal_lines.entry_id')
            ->whereNull('gl_journal_entries.id')
            ->select('gl_journal_lines.company_id')
            ->addSelect(DB::raw('count(*) as orphans'))
            ->groupBy('gl_journal_lines.company_id')
            ->get();

        foreach ($orphanRows as $row) {
            $findings[] = [
                'entry' => '-',
                'entry_no' => '-',
                'company' => (int) $row->company_id,
                'finding' => sprintf('%s line(s) have no journal entry', number_format((int) $row->orphans)),
            ];
        }

        // 5. Immutability is only real if nobody forgot to put the hatch back.
        //
        // This one finding has no `company`, deliberately: `gl_ledger_barrier` is a single global row, so
        // there is no tenant to attribute it to, and the engine reports what it cannot attribute
        // instead of inventing one. Filing it under company 1 would hide a deployment-wide fact inside one
        // company's register.
        if (DB::table('gl_ledger_barrier')->where('id', 1)->exists()) {
            $findings[] = [
                'entry' => '-',
                'entry_no' => '-',
                'finding' => 'the gl_ledger_barrier row is present — ledger immutability is currently OFF',
            ];
        }

        // Every finding leaves this method with the same keys and the same types, because the design reads
        // `company` off them to decide whose alert to raise. The drift rows are built as
        // `$label + ['finding' => …]` and the two hand-built rows are not, so without this the returned
        // arrays differ in exactly the field the alert engine cannot afford to guess — and the barrier row
        // legitimately has no company at all, which is why the cast is conditional rather than uniform.
        $normalised = [];

        foreach ($findings as $finding) {
            $line = [
                'entry' => (string) $finding['entry'],
                'entry_no' => (string) $finding['entry_no'],
                'finding' => (string) $finding['finding'],
            ];

            if (array_key_exists('company', $finding)) {
                $line['company'] = (int) $finding['company'];
            }

            $normalised[] = $line;
        }

        return $normalised;
    }

    /** Posted entries scanned, for the command's summary line. */
    public function postedEntryCount(): int
    {
        // Raw count rather than the model: this sweep is deliberately cross-tenant, and the
        // tenant scope would either throw (no context in a scheduler run) or hide other companies.
        return (int) DB::table('gl_journal_entries')->where('status', JournalEntry::Posted)->count();
    }

    /**
     * Findings are read by a person, so they quote money the way a screen does. The columns hold
     * base-currency minor units, and a raw integer reads as a hundredfold amount on a
     * two-decimal currency.
     */
    private function money(int $minorUnits, string $currency): string
    {
        return Money::ofMinor($minorUnits, $currency)->format();
    }
}
