<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\ReleaseTripAssignment;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;

/**
 * §10.2's `planned|assigned → on_hold` and back, with the vehicle held or freed accordingly.
 *
 * A hold is a pre-departure blocker — a licence renewal that has not landed, a trailer in the shop, a
 * customer who has not confirmed the load window. The truck it names must **not** stay reserved while
 * it sits: §10.2's dispatch board counts a held unit as free, and a hold that kept the reservation
 * would idle a truck nobody can use. So the assignment is released here, and the next
 * {@see DispatchTrip} re-runs the full §10.2 gate — which is the point: the blocker being cleared is
 * exactly the thing that has to be re-checked.
 *
 * Both directions share one Action because the machine's two edges are the same decision seen from
 * either side, and a separate `ReleaseHoldTrip` would double the places the release rule can drift.
 */
final class HoldTrip
{
    public function __construct(private readonly ReleaseTripAssignment $releaseAssignment) {}

    public function __invoke(Trip $trip, ?string $reason = null, ?int $actorId = null): Trip
    {
        return DB::transaction(function () use ($trip, $reason, $actorId): Trip {
            if ($trip->status === TripStatus::OnHold) {
                $trip->moveTo(TripStatus::Planned, null, [], ActorKind::User, $actorId);
                $trip->save();

                return $trip->refresh();
            }

            $trip->moveTo(TripStatus::OnHold, $reason, [], ActorKind::User, $actorId);
            $trip->save();

            ($this->releaseAssignment)($trip->id);

            return $trip->refresh();
        });
    }
}
