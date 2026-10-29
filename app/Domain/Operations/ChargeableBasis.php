<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * §4.1 CHARGEABLE's `basis` — what the amount is per.
 *
 * It mirrors the job's own rate type so the invoice line that Phase 4 builds can print a unit rate
 * and a quantity instead of an unexplained total.
 */
enum ChargeableBasis: string
{
    case PerTonne = 'per_tonne';
    case PerTrip = 'per_trip';
    case PerKm = 'per_km';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::PerTonne => 'Per tonne',
            self::PerTrip => 'Per trip',
            self::PerKm => 'Per km',
            self::Fixed => 'Fixed',
        };
    }

    /**
     * The job rate this chargeable is derived from, when there is one. `per_km` is absent on purpose:
     * a POD carries tonnage, not a measured distance, so a mileage charge cannot be built from it.
     */
    public static function fromRate(RouteRateType $rate): ?self
    {
        return match ($rate) {
            RouteRateType::PerTonne => self::PerTonne,
            RouteRateType::PerTrip => self::PerTrip,
            RouteRateType::PerKm => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
