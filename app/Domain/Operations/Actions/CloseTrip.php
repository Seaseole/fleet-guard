<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Models\TripSettlement;
use App\Domain\People\TripSettlementStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The `completed → closed`: the costs are final and the period they fall in is knowable.
 *
 * `costs_frozen` is set in the same statement as the state move, and the schema backs it
 * (`trips_frozen_is_closed` refuses a frozen row in any other state), because the two halves of this
 * claim — "this trip is finished" and "you may not edit its numbers" — are the promise the ledger is
 * built on. The design states the failure mode plainly: gate the posting on `closed` and the parts stock and
 * the month are both wrong mid-period, which is the defect an owner discovers at year end.
 *
 * Assigns this edge to the Accountant and the Owner. Authorization is the screen's (the design puts
 * `trips.close` on the route), but it is re-asserted here because a future queue or API path that
 * forgets would otherwise be able to finalise a month's numbers on someone else's behalf.
 */
final class CloseTrip
{
    public function __invoke(Trip $trip, ?int $actorId = null): Trip
    {
        if ($trip->status !== TripStatus::Completed) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s. A trip closes from `completed`, never before its facts are in.',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        if (! Gate::allows('close', $trip)) {
            throw new InvalidArgumentException('Closing a trip is an accounting act, and this seat is not an accounting seat.');
        }

        // The `completed → closed` needs the settlement recorded. The gate fires only for
        // trips that actually advanced cash to the driver — a settlement sheet for a trip that
        // never held float would be paper for its own sake. Draws OPS → PEOPLE, so reading
        // the settlement table from this side is the declared direction, not a hidden join.
        if (TripCost::query()->where('trip_id', $trip->id)->where('cost_type', TripCostType::Advance->value)->exists()) {
            $settled = TripSettlement::query()
                ->where('trip_id', $trip->id)
                ->where('status', TripSettlementStatus::Settled->value)
                ->exists();

            if (! $settled) {
                throw new InvalidArgumentException(sprintf(
                    'Trip %s advanced driver cash and has no settled settlement — the design closes a trip only once the sheet is signed.',
                    $trip->trip_no,
                ));
            }
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
