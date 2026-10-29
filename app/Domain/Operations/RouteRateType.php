<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What one number on a rate card means (§4.1 `route_rates.rate_type`).
 *
 * The basis is part of the price, not a detail: `150000` is BWP 1 500 per trip on a short haul and
 * BWP 1 500 per tonne on a cross-border leg. §4.1's JOB carries its own `rate_type`, so a job may
 * be quoted per trip even where the standing card is per tonne — the card states the default, the
 * job states what was agreed.
 */
enum RouteRateType: string
{
    case PerTonne = 'per_tonne';
    case PerTrip = 'per_trip';
    case PerKm = 'per_km';

    public function label(): string
    {
        return match ($this) {
            self::PerTonne => 'Per tonne',
            self::PerTrip => 'Per trip',
            self::PerKm => 'Per kilometre',
        };
    }

    /** What follows the amount on the card: "BWP 1 500 per tonne". */
    public function unit(): string
    {
        return match ($this) {
            self::PerTonne => 'per tonne',
            self::PerTrip => 'per trip',
            self::PerKm => 'per km',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
