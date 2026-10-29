<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Data;

/**
 * The whole validation outcome: rows the engine could take, plus failures that belong to no single
 * row (a missing header, an unbalanced file, a re-upload of something already posted).
 *
 * Totals are read from the row values rather than carried separately, so a dataset that has no
 * money (every master file) simply totals zero and a trial balance cannot disagree with its rows.
 */
final class CutoverValidation
{
    /**
     * @param  list<CutoverRowResult>  $rows
     * @param  list<string>  $batchErrors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $batchErrors,
    ) {}

    public function isValid(): bool
    {
        if ($this->batchErrors !== []) {
            return false;
        }

        foreach ($this->rows as $row) {
            if (! $row->isValid()) {
                return false;
            }
        }

        return $this->rows !== [];
    }

    public function totalDebit(): int
    {
        return array_sum(array_map(
            static fn (CutoverRowResult $row): int => (int) ($row->values['debit_minor'] ?? 0),
            $this->rows,
        ));
    }

    public function totalCredit(): int
    {
        return array_sum(array_map(
            static fn (CutoverRowResult $row): int => (int) ($row->values['credit_minor'] ?? 0),
            $this->rows,
        ));
    }

    public function validRowCount(): int
    {
        return count(array_filter($this->rows, static fn (CutoverRowResult $row): bool => $row->isValid()));
    }

    public function errorRowCount(): int
    {
        return count($this->rows) - $this->validRowCount();
    }
}
