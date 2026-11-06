<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * How the money arrived, and the account it landed in (the `PaymentReceived` debit side).
 *
 * Four values because the fleet's cash actually comes from four places. `card` and `mobile_money` both
 * debit `1120 Bank — BWP operating` because COA v1 has no settlement-account row for either — the float
 * clears into the same operating account within days, and inventing an account per rail would be a chart
 * the ledger never reconciles. Phase 5's bank reconciliation is where a `11xx` per-rail account earns its
 * keep, and when it does, this method is the one line that changes.
 */
enum PaymentMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Card = 'card';
    case MobileMoney = 'mobile_money';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank transfer (EFT)',
            self::Cash => 'Cash at the desk',
            self::Card => 'Card',
            self::MobileMoney => 'Mobile money',
        };
    }

    /** The COA v1 code the receipt debits. */
    public function accountCode(): string
    {
        return match ($this) {
            self::Cash => '1110',
            self::Bank, self::Card, self::MobileMoney => '1120',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
