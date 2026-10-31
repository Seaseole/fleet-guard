<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\AssignVehicle;
use App\Domain\Fleet\Actions\ReleaseTripAssignment;
use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Exceptions\DispatchRefused;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\DispatchGate;
use App\Domain\Operations\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * §10.2's `planned → assigned` — the transition the phase's definition of done is written against:
 * **dispatch refuses an expired licence**.
 *
 * Two things happen inside one transaction, in this order, and the order is the design:
 *
 * 1. the trip's own row is re-read **`FOR UPDATE`**, and both the edge and {@see DispatchGate} are
 *    answered against *that* row. Everything before the lock is a fast refusal for a stale page, not a
 *    decision: two dispatchers on one trip both pass the check outside, and the one who arrives second
 *    then waits on the row and reads a trip that has already left;
 * 2. {@see DispatchGate} asks every question §10.2 lists *as of the trip's start date* — unit
 *    availability, the driver's status and both papers, and whether a filed document (the insurance
 *    certificate, the licence scan) has since expired. All findings come back together so a
 *    dispatcher fixes the trip in one pass instead of discovering the second problem after the first;
 * 3. Fleet reserves the vehicle. `AssignVehicle` owns the overlap arithmetic and locks the rows it
 *    checks (D4: an invariant is a synchronous call, not an event), so the reservation is the single
 *    authority on "is this truck free", and the gate adds what Fleet cannot see — the papers.
 *
 * **The lock order, so two Actions cannot deadlock on each other: trip → truck → trailer →
 * vehicle_assignments.** Every Action that ends up in Fleet goes through this Action or through
 * {@see ReleaseTripAssignment}, which takes the asset rows before the windows
 * they hold for exactly that reason.
 *
 * A refusal writes nothing. The trip stays `planned`, no assignment exists, and the reason is a
 * message rather than a log line, because §11.2's `TripAssigned` subscribers fire *after* both halves
 * succeed.
 */
final class DispatchTrip
{
    public function __construct(
        private readonly DispatchGate $gate,
        private readonly AssignVehicle $assign,
    ) {}

    public function __invoke(Trip $trip, ?int $actorId = null): Trip
    {
        // The edge is checked before the lock is taken. Asking the papers of a trip that is already
        // assigned, or never was planned, produces a refusal that blames the crew for the caller's
        // click — and the trip the screen handed in may be a snapshot of a state that has moved on.
        // It is a fast refusal only: the decision is the one taken against the locked row below.
        if ($trip->status !== TripStatus::Planned) {
            throw new \InvalidArgumentException(sprintf(
                'Trip %s is %s; only a planned trip can be dispatched (§10.2).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        return DB::transaction(function () use ($trip, $actorId): Trip {
            // The row, not the object the screen brought. `planned → assigned` is the one edge where two
            // people can both be right a moment apart — the board renders for whoever opens it, and a
            // dispatcher watching the same cell answers "who got it first" only if the second of them
            // reads the state the first committed.
            /** @var Trip $locked */
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TripStatus::Planned) {
                throw new \InvalidArgumentException(sprintf(
                    'Trip %s is %s; only a planned trip can be dispatched (§10.2).',
                    $locked->trip_no,
                    $locked->status->label(),
                ));
            }

            $findings = $this->gate->findings($locked);

            if ($findings !== []) {
                throw DispatchRefused::papersOrFleetUnavailable($findings);
            }

            $from = $locked->planned_departure_at === null
                ? CarbonImmutable::now()
                : CarbonImmutable::instance($locked->planned_departure_at);

            try {
                // The reservation is recorded against the trip, so releasing it later answers "what
                // was this truck held for" without a join back through the trip's own timeline.
                ($this->assign)(new AssignmentDto(
                    truckId: (int) $locked->truck_id,
                    fromAt: $from->toDateTimeString(),
                    trailerId: $locked->trailer_id === null ? null : (int) $locked->trailer_id,
                    driverId: (int) $locked->driver_id,
                    reason: 'Dispatched on trip '.$locked->trip_no,
                    tripId: $locked->id,
                ));
            } catch (AssignmentRejected $refused) {
                throw DispatchRefused::fleetRefusedReservation($refused->getMessage());
            }

            $locked->moveTo(TripStatus::Assigned, null, [], ActorKind::User, $actorId);
            $locked->save();

            return $locked->refresh();
        });
    }
}
