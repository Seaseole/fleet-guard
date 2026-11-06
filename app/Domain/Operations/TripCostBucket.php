<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * The five direct-cost buckets on `trips`, one case per rollup column.
 *
 * The bucket, not the account, is what the screen and the dashboard read: a sixteenth expense
 * account added later joins a bucket rather than a report query.
 */
enum TripCostBucket: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Border = 'border';
    case Driver = 'driver';
    case Other = 'other';

    public function column(): string
    {
        return match ($this) {
            self::Fuel => 'fuel_cost_base',
            self::Maintenance => 'maintenance_cost_base',
            self::Border => 'border_cost_base',
            self::Driver => 'driver_cost_base',
            self::Other => 'other_direct_cost_base',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Maintenance => 'Maintenance',
            self::Border => 'Border',
            self::Driver => 'Driver',
            self::Other => 'Other direct',
        };
    }

    /** Which posted account codes land in this bucket — the rollup's whole mapping table. */
    /** @return list<string> */
    public function accountCodes(): array
    {
        return match ($this) {
            self::Fuel => ['5100'],
            self::Maintenance => ['5300', '5310', '5320'],
            self::Border => ['5400'],
            self::Driver => ['5200', '5420'],
            self::Other => ['5410', '5430', '5900'],
        };
    }

    /**
     * The bucket an account code lands in, or null for an account the rollup must not count.
     *
     * Null is deliberately the answer for a **revenue** account and for the advance receivable
     * (1160): the rollup adds up direct costs, and an account that is not one must not silently
     * join "other".
     */
    public static function forAccountCode(string $code): ?self
    {
        foreach (self::cases() as $bucket) {
            if (in_array($code, $bucket->accountCodes(), true)) {
                return $bucket;
            }
        }

        return null;
    }

    /** @return list<string> the five `trips` columns, in bucket order */
    public static function columns(): array
    {
        return array_map(static fn (self $bucket): string => $bucket->column(), self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $bucket): string => $bucket->value, self::cases());
    }
}
