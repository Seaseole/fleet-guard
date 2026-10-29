<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

enum BankAccountTypes: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Wallet = 'wallet';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::Cash => 'Petty cash',
            self::Wallet => 'Mobile wallet',
        };
    }

    /**
     * Petty cash and wallets do not carry a bank account number; §4.2's column is nullable for
     * exactly that reason, and the form hides the field instead of storing "N/A" as a secret.
     */
    public function takesAccountNumber(): bool
    {
        return $this === self::Bank;
    }
}
