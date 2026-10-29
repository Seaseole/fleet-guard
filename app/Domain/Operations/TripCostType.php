<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * §4.1's `trip_costs.cost_type`, and the two things a poster needs from it: which account the cost
 * debits, and which §6.6 bucket the rollup adds it to.
 *
 * The mapping lives on the enum rather than in a switch inside the Action so "where does a border
 * cost post?" has one answer in the codebase. `advance` deliberately posts to a receivable and has
 * no bucket: an advance is not an expense until it is settled (§6.8's `AdvanceDisbursed`), and
 * counting it as a direct cost would make every trip carrying cash look unprofitable.
 */
enum TripCostType: string
{
    case Fuel = 'fuel';
    case Parts = 'parts';
    case Border = 'border';
    case Labour = 'labour';
    case Lodging = 'lodging';
    case Toll = 'toll';
    case Advance = 'advance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Parts => 'Parts and repairs',
            self::Border => 'Border and customs',
            self::Labour => 'Driver labour',
            self::Lodging => 'Lodging and subsistence',
            self::Toll => 'Tolls and road charges',
            self::Advance => 'Driver advance',
            self::Other => 'Other direct cost',
        };
    }

    /** The seeded COA account this cost debits (codes are stable; ids are not). */
    public function accountCode(): string
    {
        return match ($this) {
            self::Fuel => '5100',
            self::Parts => '5300',
            self::Border => '5400',
            self::Labour => '5200',
            self::Lodging => '5420',
            self::Toll => '5410',
            self::Advance => '1160',
            self::Other => '5430',
        };
    }

    /** §6.6's bucket, or null for a cost that is not a P&L expense (the advance). */
    public function bucket(): ?TripCostBucket
    {
        return match ($this) {
            self::Fuel => TripCostBucket::Fuel,
            self::Parts => TripCostBucket::Maintenance,
            self::Border => TripCostBucket::Border,
            self::Labour, self::Lodging => TripCostBucket::Driver,
            self::Toll, self::Other => TripCostBucket::Other,
            self::Advance => null,
        };
    }

    /** The driver dimension is required by 5200 and 1160, so the trip must name a driver. */
    public function requiresDriver(): bool
    {
        return $this === self::Labour || $this === self::Advance;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
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
