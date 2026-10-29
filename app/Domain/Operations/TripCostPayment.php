<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * How a trip cost was settled at capture time — the credit side of the cost's journal entry.
 *
 * The three cases are the three places money actually leaves from (§6.8): the cash box, the bank
 * account, or the supplier's ledger. `supplier_credit` is not "unpaid": the bill exists, the cost is
 * real, and the payable is the liability that says so. A fourth case would be a fourth way to move
 * money, and picking it here would be picking it without the bank module's reconciliation behind it.
 */
enum TripCostPayment: string
{
    case PettyCash = 'petty_cash';
    case Bank = 'bank';
    case SupplierCredit = 'supplier_credit';

    public function label(): string
    {
        return match ($this) {
            self::PettyCash => 'Petty cash',
            self::Bank => 'Bank transfer',
            self::SupplierCredit => 'On the supplier account',
        };
    }

    /** The account credited when this cost is posted. */
    public function accountCode(): string
    {
        return match ($this) {
            self::PettyCash => '1110',
            self::Bank => '1120',
            self::SupplierCredit => '2110',
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
