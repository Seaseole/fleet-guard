<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * §4.1 CHARGEABLE's `type` — what the customer is being charged for, before it is an invoice line.
 *
 * Only `freight` is derived from a POD in this phase. The rest are captured by hand when the work
 * that earns them ships (detention is a waiting-time fact, customs a border one), which is why the
 * vocabulary exists now and the builder names the one type it can honestly derive.
 */
enum ChargeableType: string
{
    case Freight = 'freight';
    case Detention = 'detention';
    case Accessories = 'accessories';
    case CustomsRecharge = 'customs_recharge';
    case FuelRecharge = 'fuel_recharge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Freight => 'Freight',
            self::Detention => 'Detention',
            self::Accessories => 'Accessories',
            self::CustomsRecharge => 'Customs recharge',
            self::FuelRecharge => 'Fuel recharge',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
