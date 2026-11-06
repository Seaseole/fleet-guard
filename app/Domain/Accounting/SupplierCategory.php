<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * What a supplier supplies (`BILL.category`).
 *
 * The six values are the design's own, not a new list: Phase 5 will default a bill's category from the
 * supplier, and a bill is what carries the expense account. Adding a value here without adding it
 * there would leave the bill form unable to accept what the master row claims.
 */
enum SupplierCategory: string
{
    case Fuel = 'fuel';
    case Parts = 'parts';
    case Labour = 'labour';
    case Insurance = 'insurance';
    case Lease = 'lease';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Parts => 'Parts',
            self::Labour => 'Labour',
            self::Insurance => 'Insurance',
            self::Lease => 'Lease',
            self::Other => 'Other',
        };
    }

    /** The `ui/badge` tone for the register. */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel, self::Parts => 'accent',
            default => 'neutral',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
