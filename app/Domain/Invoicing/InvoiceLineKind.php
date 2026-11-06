<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * What a billed line actually charges for, and the ledger account that answers to it.
 *
 * The six chargeable values are inherited verbatim from `chargeables.type` so a POD's earning
 * arrives at the same account an office-raised line of the same kind does; `manual` is the seventh because
 * an invoice drafted from nothing has no chargeable behind it, and the list has no word for it.
 *
 * `accountCode()` is the answer to "which account does this line hit" — one place, one file, exactly as
 * the design asks its posting rules to live. It is keyed here rather than in a registry table because the
 * mapping is a decision, not a preference: `4100` requires truck **and** trip **and** customer (the
 * mask), which is only satisfiable by a line that names a freight leg, so a `freight` line that could not
 * post to 4100 is a bug rather than a configuration.
 *
 * The account is also **copied onto the row** at write time. If the accountant re-codes the chart in a year,
 * rows already posted must keep saying what they actually posted to.
 */
enum InvoiceLineKind: string
{
    case Freight = 'freight';
    case Detention = 'detention';
    case Accessories = 'accessories';
    case CustomsRecharge = 'customs_recharge';
    case FuelRecharge = 'fuel_recharge';
    case Other = 'other';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Freight => 'Freight',
            self::Detention => 'Waiting time / detention',
            self::Accessories => 'Accessories',
            self::CustomsRecharge => 'Customs and clearing recharged',
            self::FuelRecharge => 'Fuel recharged',
            self::Other => 'Other charge',
            self::Manual => 'Manual line',
        };
    }

    /** The COA v1 code this line's revenue is credited to. */
    public function accountCode(): string
    {
        return match ($this) {
            self::Freight => '4100',
            self::Detention => '4200',
            self::FuelRecharge => '4300',
            self::CustomsRecharge => '4400',
            self::Accessories, self::Other, self::Manual => '4900',
        };
    }

    /**
     * The dimensions this account demands. Only 4100 asks for a truck and a trip; 4200/4300/4400 ask
     * for the trip alone. A line that cannot name what its account requires is refused before the row is
     * written rather than at the ledger, so the answer arrives on the form.
     *
     * @return list<'trip'|'truck'|'customer'>
     */
    public function requiredDimensions(): array
    {
        return match ($this) {
            self::Freight => ['trip', 'truck', 'customer'],
            self::Detention, self::CustomsRecharge, self::FuelRecharge => ['trip', 'customer'],
            self::Accessories, self::Other, self::Manual => ['customer'],
        };
    }

    /**
     * The kinds the billing run may produce from a chargeable — `manual` is the office's, never derived.
     *
     * @return list<self>
     */
    public static function chargeableKinds(): array
    {
        return [self::Freight, self::Detention, self::Accessories, self::CustomsRecharge, self::FuelRecharge, self::Other];
    }
}
