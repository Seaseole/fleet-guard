<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use Illuminate\Support\Facades\DB;

/**
 * `documents:number-integrity` (§7.4, last rule). Gap-free cannot be fully *prevented* — a human
 * with a SQL client can delete a row or edit a counter — so it is detected nightly instead.
 *
 * Per sequence row: the counter is positive and its format still renders.
 * Per (company, scope): the count of numbered documents equals Σ(next_value − 1), numbers are
 * unique, and no row has left the draft state without one.
 *
 * A scope whose document store is not declared in `config/fleet.php` is reported as **unchecked**
 * rather than passed. A green run has to mean something.
 */
final class NumberIntegrityChecker
{
    /** @return list<Finding> */
    public function check(?int $companyId = null): array
    {
        $rows = DB::table('number_sequences')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('company_id')
            ->orderBy('scope')
            ->orderBy('period_key')
            ->get()
            ->map(fn (object $row): NumberSequenceRow => NumberSequenceRow::fromDatabase((array) $row))
            ->all();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = $this->inspectCounter($row);
        }

        /** @var array<string, list<NumberSequenceRow>> $byScope */
        $byScope = [];

        foreach ($rows as $row) {
            $byScope["{$row->companyId}:{$row->scope}"][] = $row;
        }

        foreach ($byScope as $group) {
            $findings = array_merge($findings, $this->inspectStore($group));
        }

        return $findings;
    }

    private function inspectCounter(NumberSequenceRow $row): Finding
    {
        if ($row->nextValue <= 0) {
            return new Finding($row->companyId, $row->scope, $row->periodKey, Finding::Drift,
                "counter is {$row->nextValue}, which CHECK (next_value > 0) should have refused");
        }

        try {
            NumberFormat::render($row->format, $row->periodKey, $row->nextValue);
        } catch (NumberingError $e) {
            return new Finding($row->companyId, $row->scope, $row->periodKey, Finding::Drift, $e->getMessage());
        }

        return new Finding($row->companyId, $row->scope, $row->periodKey, Finding::Ok,
            "next allocation will be {$row->nextValue}");
    }

    /**
     * Every period row for one (company, scope), checked against that scope's document store.
     *
     * @param  list<NumberSequenceRow>  $rows
     * @return list<Finding>
     */
    private function inspectStore(array $rows): array
    {
        $company = $rows[0]->companyId;
        $scope = $rows[0]->scope;

        $store = config("fleet.numbering.stores.{$scope}");

        if (! is_array($store)) {
            return [new Finding($company, $scope, '*', Finding::Unchecked,
                'no document store declared in config/fleet.php — the counter advances but nothing '
                .'proves the documents agree with it yet')];
        }

        $table = (string) ($store['table'] ?? '');
        $column = (string) ($store['number_column'] ?? 'number');

        if ($table === '' || ! DB::getSchemaBuilder()->hasTable($table)) {
            return [new Finding($company, $scope, '*', Finding::Unchecked, "table {$table} does not exist yet")];
        }

        $expected = array_sum(array_map(fn (NumberSequenceRow $r) => $r->issuedCount(), $rows));
        $numbered = DB::table($table)->where('company_id', $company)->whereNotNull($column)->count();

        $findings = [new Finding($company, $scope, '*', $numbered === $expected ? Finding::Ok : Finding::Drift,
            $numbered === $expected
                ? "{$numbered} numbered rows agree with the counter"
                : "counter implies {$expected} issued documents, {$table} has {$numbered} — "
                    .($numbered > $expected
                        ? 'numbers were assigned without the counter, or a counter was rewound'
                        : 'a number was burned without a document surviving it'))];

        $duplicates = DB::table($table)
            ->where('company_id', $company)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck($column)
            ->all();

        if ($duplicates !== []) {
            $findings[] = new Finding($company, $scope, '*', Finding::Drift,
                'duplicate numbers: '.implode(', ', array_map(static fn (mixed $v): string => (string) $v, $duplicates)));
        }

        // §7.4: a document that has left draft without a number means allocation happened at the
        // wrong moment — at creation instead of at issue, or not at all.
        $statusColumn = $store['status_column'] ?? null;
        $draftStatus = $store['draft_status'] ?? null;

        if (is_string($statusColumn) && is_string($draftStatus)) {
            $unnumbered = DB::table($table)
                ->where('company_id', $company)
                ->where($statusColumn, '!=', $draftStatus)
                ->whereNull($column)
                ->count();

            if ($unnumbered > 0) {
                $findings[] = new Finding($company, $scope, '*', Finding::Drift,
                    "{$unnumbered} non-draft rows have no number — numbering must happen at issue (§7.4)");
            }
        }

        return $findings;
    }
}
