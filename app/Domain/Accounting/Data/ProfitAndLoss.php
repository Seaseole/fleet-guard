<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Kernel\Ledger\Data\AccountType;

/**
 * The profit and loss statement for one window: financial statements read the ledger, and
 * this is one of them. Revenue and expense rows come from the accounts whose
 * {@see AccountType::statement()} axis is `profit_and_loss` —
 * the axis, not a code-range guess, because "5000–5999 is revenue" is a numbering habit.
 *
 * Only accounts the window actually moved appear; a zero row tells the reader nothing and makes
 * the sheet longer than the book.
 */
final class ProfitAndLoss
{
    /**
     * @param  list<ProfitAndLossLine>  $revenueLines
     * @param  list<ProfitAndLossLine>  $expenseLines
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $currencyCode,
        public readonly array $revenueLines,
        public readonly array $expenseLines,
    ) {}

    public function totalRevenueMinor(): int
    {
        return array_sum(array_map(static fn (ProfitAndLossLine $line): int => $line->amountMinor, $this->revenueLines));
    }

    public function totalExpenseMinor(): int
    {
        return array_sum(array_map(static fn (ProfitAndLossLine $line): int => $line->amountMinor, $this->expenseLines));
    }

    public function netProfitMinor(): int
    {
        return $this->totalRevenueMinor() - $this->totalExpenseMinor();
    }
}
