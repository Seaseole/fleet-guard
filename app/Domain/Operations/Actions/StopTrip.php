<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\EndTruckTrip;
use App\Domain\Fleet\Actions\ReleaseTripAssignment;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;

/**
 * Stops a trip, choosing §10.2's `cancelled` or `aborted` from where it already is.
 *
 * The two exits are different accounting facts, not two words for one thing:
 *
 * - **cancelled** is pre-departure. Nothing happened, no cost is real, and the reservation simply
 *   goes away — the truck was never on a trip, so there is nothing to return;
 * - **aborted** is post-departure. The fuel already went, the border fees were paid, the driver slept
 *   somewhere. §10.2 says plainly that *costs still post and the trip may still be billed*, so the
 *   only housekeeping here is the vehicle: release the reservation and bring the truck home, leaving
 *   every fact that was already true.
 *
 * Both require a reason at the machine itself (`transitionsRequiringReason()`), because "why was this
 * corridor stopped in March" is the question a compliance review asks first.
 */
final class StopTrip
{
    public function __construct(
        private readonly FleetDirectory $fleet,
        private readonly ReleaseTripAssignment $releaseAssignment,
        private readonly EndTruckTrip $endTruckTrip,
    ) {}

    public function __invoke(Trip $trip, string $reason, ?int $actorId = null): Trip
    {
        return DB::transaction(function () use ($trip, $reason, $actorId): Trip {
            // Locked, because the cancelled/aborted fork below reads the status: a trip that departed
            // while this transaction was waiting is an abort, not a cancellation, and the difference is
            // whether the truck has to be called back from the road.
            /** @var Trip $locked */
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            $aborted = $locked->status->isPreDeparture() === false;

            $locked->moveTo($aborted ? TripStatus::Aborted : TripStatus::Cancelled, $reason, [], ActorKind::User, $actorId);
            $locked->save();

            ($this->releaseAssignment)($locked->id);

            // Only an aborted trip had put its truck on the road; §10.2's cancelled branch never
            // reached departure, and EndTruckTrip would rightly refuse to "return" a truck that is
            // still sitting in the yard.
            if ($aborted && $locked->truck_id !== null) {
                $truck = $this->fleet->truck((int) $locked->truck_id);

                if ($truck !== null && $truck->status === FleetStatus::OnTrip) {
                    ($this->endTruckTrip)((int) $locked->truck_id, 'Aborted on trip '.$locked->trip_no);
                }
            }

            return $locked->refresh();
        });
    }
}
