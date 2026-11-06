<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Data;

/**
 * `gl_accounts.type` is `string(16)` + this enum + a DB CHECK, not a MySQL ENUM.
 *
 * The DB enforces the same list (decision 2), so a value that is not in this enum cannot be
 * stored even by someone editing the row in a SQL client.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }

    /**
     * The statement axis an account reports on. The trial balance and the P&L read this rather
     * than re-deriving it from the code range, because "5000-5999 is revenue" is an accountant's
     * numbering habit, not an invariant.
     */
    public function statement(): string
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => 'balance_sheet',
            self::Revenue, self::Expense => 'profit_and_loss',
        };
    }
}
