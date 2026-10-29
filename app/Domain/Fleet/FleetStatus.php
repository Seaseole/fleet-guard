<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * The shared lifecycle of a physical asset (§4.3, §10.1).
 *
 * One enum for trucks, trailers and equipment because the states are the same words and the badge
 * should read the same way on every register. Equipment simply never enters `on_trip` — nothing is
 * dispatched on a generator — and its transition table does not list it, so the DB CHECK and the
 * table agree.
 */
enum FleetStatus: string
{
    case Available = 'available';
    case OnTrip = 'on_trip';
    case InWorkshop = 'in_workshop';
    case OutOfService = 'out_of_service';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::OnTrip => 'On trip',
            self::InWorkshop => 'In workshop',
            self::OutOfService => 'Out of service',
            self::Sold => 'Sold',
        };
    }

    /** The `ui/badge` tone for a register (DESIGN-SYSTEM.md §1.5). */
    public function tone(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::OnTrip => 'accent',
            self::InWorkshop => 'warning',
            self::OutOfService => 'danger',
            self::Sold => 'neutral',
        };
    }

    /** Only an available asset can be named on a new assignment (§10.2). */
    public function mayBeDispatched(): bool
    {
        return $this === self::Available;
    }

    public function isTerminal(): bool
    {
        return $this === self::Sold;
    }
}
