<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\BeginTruckTrip;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Exceptions\DispatchRefused;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * §10.2's `assigned → departed` (or `loaded → departed`): the trip leaves, and the truck's mileage
 * starts being this trip's responsibility.
 *
 * Three facts are written in one transaction, in an order that matters:
 *
 * 1. the **opening odometer is tested against the truck's projection** before anything is stored
 *    (§4.3: the reading that leaves cannot be below the reading that arrived last time — that is the
 *    incident, and it is refused here rather than filed as suspect, because a departure under the
 *    projection makes every later variance on this trip meaningless);
 * 2. the reading is recorded **through Fleet's own Action**, so the truck's projection, its audit row
 *    and its service-schedule arithmetic all move together. Operations does not write `trucks`;
 * 3. the truck goes `available → on_trip`, which is the state §10.1 says only a trip may cause —
 *    Phase 1's `AssignVehicle` deliberately stopped short of it, saying departure is a Phase 2 fact.
 *    This is that moment.
 *
 * §10.2 also asks for an opening *fuel* reading at departure. It is not here because Phase 3 owns the
 * tanks, and a half-built fuel gate would be a gate that always passes — the honest place for it is
 * the slice that has the data.
 */
final class DepartTrip
{
    public function __construct(
        private readonly FleetDirectory $fleet,
        private readonly RecordOdometerReading $recordOdometer,
        private readonly BeginTruckTrip $beginTruckTrip,
    ) {}

    public function __invoke(
        Trip $trip,
        int $odometerStartKm,
        ?float $engineHoursStart = null,
        ?int $actorId = null,
        ActorKind $actorKind = ActorKind::User,
    ): Trip {
        if (! in_array($trip->status, [TripStatus::Assigned, TripStatus::Loaded], true)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s; only an assigned or loaded trip can depart (§10.2).',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        if ($odometerStartKm < 0) {
            throw new InvalidArgumentException('An odometer reading cannot be negative.');
        }

        $truck = $this->fleet->truck((int) $trip->truck_id)
            ?? throw DispatchRefused::unitMissing('truck', (int) $trip->truck_id);

        if (! $truck->odometerReaches($odometerStartKm)) {
            throw new InvalidArgumentException(sprintf(
                'The opening reading (%s km) is below %s\'s recorded odometer (%s km). §4.3: a reading that runs backwards is an incident, not a departure — record it against the truck as a correction first.',
                number_format($odometerStartKm),
                $truck->label,
                number_format((int) $truck->odometerKm),
            ));
        }

        return DB::transaction(function () use ($trip, $odometerStartKm, $engineHoursStart, $truck, $actorId, $actorKind): Trip {
            ($this->recordOdometer)(new OdometerReadingDto(
                truckId: $truck->id,
                odometerKm: $odometerStartKm,
                readOn: now($trip->planned_departure_at?->timezone->getName() ?? null)->toDateString(),
                source: OdometerSource::TripDeparture,
                engineHours: $engineHoursStart,
                tripId: $trip->id,
            ));

            $trip->fill([
                'odometer_start_km' => $odometerStartKm,
                'engine_hours_start_10_2' => $engineHoursStart,
                'departed_at' => now(),
            ]);

            $trip->moveTo(TripStatus::Departed, null, [], $actorKind, $actorId);
            $trip->save();

            // The truck's own state move is Fleet's, with the trip named as the reason, so the
            // operational trail answers "why is this truck on a trip" from the truck's side too.
            // It goes through `BeginTruckTrip` rather than a `Truck` model Operations may not import
            // (§3.1 D5), and the id is the whole contract.
            ($this->beginTruckTrip)($truck->id, 'Departed on trip '.$trip->trip_no);

            return $trip->refresh();
        });
    }
}
