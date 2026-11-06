<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * A group of balance-sheet lines under one of the chart's own header rows ("headers are
 * the rollup rows a trial balance groups under") — Current assets, Fixed assets, Current
 * liabilities, Non-current liabilities, Equity. The heading comes from the seeded tree, not a
 * code-range guess, because the accountant's numbering habit is not an invariant.
 */
final class BalanceSheetSection
{
    /** @param list<BalanceSheetLine> $lines */
    public function __construct(
        public readonly string $heading,
        public readonly array $lines,
    ) {}

    public function totalMinor(): int
    {
        return array_sum(array_map(static fn (BalanceSheetLine $line): int => $line->amountMinor, $this->lines));
    }
}
