<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\AssignVehicle;
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
 * 1. {@see DispatchGate} asks every question §10.2 lists *as of the trip's start date* — unit
 *    availability, the driver's status and both papers, and whether a filed document (the insurance
 *    certificate, the licence scan) has since expired. All findings come back together so a
 *    dispatcher fixes the trip in one pass instead of discovering the second problem after the first;
 * 2. Fleet reserves the vehicle. `AssignVehicle` owns the overlap arithmetic and locks the rows it
 *    checks (D4: an invariant is a synchronous call, not an event), so the reservation is the single
 *    authority on "is this truck free", and the gate adds what Fleet cannot see — the papers.
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
        // The edge is checked before the gate runs. Asking the papers of a trip that is already
        // assigned, or never was planned, produces a refusal that blames the crew for the caller's
        // click — and the reservation below would then be the thing that actually notices.
        if ($trip->status !== TripStatus::Planned) {
            throw new \InvalidArgumentException(sprintf(
                'Trip %s is %s; only a planned trip can be dispatched (§10.2).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        $findings = $this->gate->findings($trip);

        if ($findings !== []) {
            throw DispatchRefused::papersOrFleetUnavailable($findings);
        }

        return DB::transaction(function () use ($trip, $actorId): Trip {
            $from = $trip->planned_departure_at === null
                ? CarbonImmutable::now()
                : CarbonImmutable::instance($trip->planned_departure_at);

            try {
                // The reservation is recorded against the trip, so releasing it later answers "what
                // was this truck held for" without a join back through the trip's own timeline.
                ($this->assign)(new AssignmentDto(
                    truckId: (int) $trip->truck_id,
                    fromAt: $from->toDateTimeString(),
                    trailerId: $trip->trailer_id === null ? null : (int) $trip->trailer_id,
                    driverId: (int) $trip->driver_id,
                    reason: 'Dispatched on trip '.$trip->trip_no,
                    tripId: $trip->id,
                ));
            } catch (AssignmentRejected $refused) {
                throw DispatchRefused::fleetRefusedReservation($refused->getMessage());
            }

            $trip->moveTo(TripStatus::Assigned, null, [], ActorKind::User, $actorId);
            $trip->save();

            return $trip->refresh();
        });
    }
}
