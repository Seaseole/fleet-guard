<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * §10.2's `completed → closed`: the costs are final and the period they fall in is knowable.
 *
 * `costs_frozen` is set in the same statement as the state move, and the schema backs it
 * (`trips_frozen_is_closed` refuses a frozen row in any other state), because the two halves of this
 * claim — "this trip is finished" and "you may not edit its numbers" — are the promise the ledger is
 * built on. §19 states the failure mode plainly: gate the posting on `closed` and the parts stock and
 * the month are both wrong mid-period, which is the defect an owner discovers at year end.
 *
 * §10.2 assigns this edge to the Accountant and the Owner. Authorization is the screen's (§9.4 puts
 * `trips.close` on the route), but it is re-asserted here because a future queue or API path that
 * forgets would otherwise be able to finalise a month's numbers on someone else's behalf.
 */
final class CloseTrip
{
    public function __invoke(Trip $trip, ?int $actorId = null): Trip
    {
        if ($trip->status !== TripStatus::Completed) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s. A trip closes from `completed`, never before its facts are in (§10.2).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        if (! Gate::allows('close', $trip)) {
            throw new InvalidArgumentException('Closing a trip is an accounting act, and this seat is not an accounting seat (§10.2).');
        }

        return DB::transaction(function () use ($trip, $actorId): Trip {
            // Set before the move so the freeze and the state are one UPDATE — the same ordering
            // `ReopenTrip` needs in reverse, and the reason `trips_frozen_is_closed` can be believed.
            $trip->costs_frozen = true;

            $trip->moveTo(TripStatus::Closed, null, [], ActorKind::User, $actorId);

            return $trip->refresh();
        });
    }
}
