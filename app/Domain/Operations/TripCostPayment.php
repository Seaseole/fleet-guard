<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * How a trip cost was settled at capture time — the credit side of the cost's journal entry.
 *
 * The three money cases are the three places money actually leaves from: the cash box, the
 * bank account, or the supplier's ledger. `supplier_credit` is not "unpaid": the bill exists, the
 * cost is real, and the payable is the liability that says so.
 *
 * `stock` is not a way to pay — it is the fourth case because diesel drawn from a bulk tank was
 * already paid for when it was delivered, so the issue's credit is `1180 Bulk fuel stock` rather
 * than a cash or payable account (Phase 3 slice 3b). It is deliberately absent from
 * {@see manualOptions()}: a person typing a toll cannot settle it with fuel, and the trip page's
 * dialog offers only what a desk can do.
 */
enum TripCostPayment: string
{
    case PettyCash = 'petty_cash';
    case Bank = 'bank';
    case SupplierCredit = 'supplier_credit';
    case Stock = 'stock';

    public function label(): string
    {
        return match ($this) {
            self::PettyCash => 'Petty cash',
            self::Bank => 'Bank transfer',
            self::SupplierCredit => 'On the supplier account',
            self::Stock => 'From bulk stock',
        };
    }

    /** The account credited when this cost is posted. */
    public function accountCode(): string
    {
        return match ($this) {
            self::PettyCash => '1110',
            self::Bank => '1120',
            self::SupplierCredit => '2110',
            self::Stock => '1180',
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

    /**
     * The cases a person may choose on a cost form. Stock is written by the fuel module, never
     * picked by hand.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            static fn (array $option): bool => $option['value'] !== self::Stock->value,
        ));
    }
}
