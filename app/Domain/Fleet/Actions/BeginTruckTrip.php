<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts a truck on a trip, by id (§10.1's `available → on_trip`).
 *
 * This exists so Operations can move a truck's state without importing `Truck`. §3.1 D5 says a module
 * may not reach into another module's model — and the alternative was a trip Action holding an
 * Eloquent object it has no business touching. The id is the contract; the state machine, the reason
 * and the event row stay here, in Fleet, where `ChangeTruckStatus` already owns them.
 *
 * `on_trip` is the one state a *departure* causes. It is not reachable by editing a truck: a truck
 * that is merely marked on a trip with no trip to return from would never come back, which is why the
 * caller must name the trip in the reason and why the transition table only admits it from
 * `available`.
 */
final class BeginTruckTrip
{
    public function __construct(private readonly ChangeTruckStatus $changeStatus) {}

    public function __invoke(int $truckId, string $reason): void
    {
        DB::transaction(function () use ($truckId, $reason): void {
            $truck = Truck::query()->find($truckId);

            if ($truck === null) {
                throw new InvalidArgumentException(sprintf('Truck #%d is not in this company\'s fleet.', $truckId));
            }

            if ($truck->status === FleetStatus::OnTrip) {
                throw new InvalidArgumentException(sprintf(
                    '%s is already on a trip. Release that trip first — one truck, one road at a time (§10.1).',
                    $truck->label(),
                ));
            }

            ($this->changeStatus)($truck, FleetStatus::OnTrip, $reason);
        });
    }
}
