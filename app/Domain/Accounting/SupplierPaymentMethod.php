<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * §6.8 — how a supplier payment left the till, and the account it credits.
 *
 * Deliberately narrower than Invoicing's `PaymentMethod` on the receipt side: this slice's §6.8 row is
 * `Dr 2110 / Cr 1010 Bank or 1020 Cash` (the COA v1 pair `1120`/`1110`), and a card or mobile-money
 * outflow has no account in the seeded chart that would not be an invention. When one arrives it joins
 * here, in the module that owns payables — not by importing the customer module's vocabulary (§3.1 D5).
 */
enum SupplierPaymentMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';

    /** The COA v1 account the payment credits (§6.8). */
    public function accountCode(): string
    {
        return match ($this) {
            self::Bank => '1120',
            self::Cash => '1110',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::Cash => 'Cash',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
