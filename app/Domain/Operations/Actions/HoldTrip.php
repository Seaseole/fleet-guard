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
            // The hold and its release are the same edge in opposite directions, so which one this is has
            // to be answered from the locked row: two tabs clicking one trip's hold button used to read
            // `planned` in both, reserve nothing twice over, and leave one of them holding the other's
            // release.
            /** @var Trip $locked */
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TripStatus::OnHold) {
                $locked->moveTo(TripStatus::Planned, null, [], ActorKind::User, $actorId);
                $locked->save();

                return $locked->refresh();
            }

            $locked->moveTo(TripStatus::OnHold, $reason, [], ActorKind::User, $actorId);
            $locked->save();

            ($this->releaseAssignment)($locked->id);

            return $locked->refresh();
        });
    }
}
