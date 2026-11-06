<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The four reasons a work order stops (the `in_progress → on_hold`).
 *
 * A free-text reason would make the register impossible to count ("how many jobs are waiting on
 * parts" is the question the stores clerk asks every Monday), and the migration's CHECK holds this
 * list so the answer is a group-by rather than a search. `awaiting_owner_approval` is here because a
 * job can be found to need more than was approved mid-work — stopping to ask again is the control
 * working, not failing.
 */
enum HoldReason: string
{
    case AwaitingParts = 'awaiting_parts';
    case ExternalGarage = 'external_garage';
    case AwaitingOwnerApproval = 'awaiting_owner_approval';
    case VehicleNeededOnTrip = 'vehicle_needed_on_trip';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingParts => 'Awaiting parts',
            self::ExternalGarage => 'At an external garage',
            self::AwaitingOwnerApproval => 'Awaiting owner approval',
            self::VehicleNeededOnTrip => 'Vehicle needed on a trip',
        };
    }
}
