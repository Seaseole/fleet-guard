<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Exceptions\LoadingRefused;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\LoadingGate;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The `assigned → loaded`: the manifest is complete, and this is where it is measured.
 *
 * The gate runs against the *whole* load list, not the row just added, which is why marking a trip
 * loaded is its own Action rather than a side effect of {@see AddLoad}: six parcels of 6 t each are
 * fine individually against a 34 t truck and 2 t over the 28 t trailer behind it, and the only moment
 * that is knowable is when the last one is on the list.
 *
 * All findings come back together for the same reason {@see DispatchGate} does: a loading bay that
 * learns about the missing waybill only after it has re-shuffled the pallets to fix the tonnage
 * loses an hour and starts treating the software as an obstacle.
 *
 * The empty case is refused rather than allowed: Already gives an empty truck its own path
 * (`assigned → departed`, "no load plan"), so a trip marked loaded with nothing on it is a manifest
 * that has not been typed, not a decision to run light.
 */
final class MarkLoaded
{
    public function __construct(private readonly LoadingGate $gate) {}

    public function __invoke(Trip $trip, ?int $actorId = null, ActorKind $actorKind = ActorKind::User): Trip
    {
        if ($trip->status !== TripStatus::Assigned) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s; only an assigned trip is marked loaded.',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        $findings = $this->gate->findings($trip);

        if ($findings !== []) {
            throw LoadingRefused::gate($findings);
        }

        return DB::transaction(function () use ($trip, $actorId, $actorKind): Trip {
            $trip->moveTo(TripStatus::Loaded, null, ['tonnes' => $trip->tonnes()], $actorKind, $actorId);

            return $trip->refresh();
        });
    }
}
