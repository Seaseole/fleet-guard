<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Actions\EndTruckTrip;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Actions\ReleaseTripAssignment;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * §10.2's `delivered → completed` — the trip's facts are in, and the truck comes home.
 *
 * The closing odometer is the one number this Action insists on, because everything downstream is
 * measured against the span it completes: fuel variance (Phase 3), cost-per-km and the corridor
 * distance figures (Phase 8). Of the §10.2 checklist's other two lines, *every crossing exited*
 * landed with slice 2f: a crossing still `planned` or `entered` is a gate the paper says the truck
 * has not cleared, and completion is where the road's facts are declared in. *Fuel reconciled* still
 * waits for Phase 3's fuel module, and a gate that has nothing to check would be a gate that always
 * passes, so it is absent rather than assumed.
 *
 * The reading goes through Fleet's `RecordOdometerReading`, so the truck's projection, its audit trail
 * and its service-schedule arithmetic all move on the same call that ends the trip (§4.3: one reading,
 * one owner).
 */
final class CompleteTrip
{
    public function __construct(
        private readonly FleetDirectory $fleet,
        private readonly RecordOdometerReading $recordOdometer,
        private readonly EndTruckTrip $endTruckTrip,
        private readonly ReleaseTripAssignment $releaseAssignment,
    ) {}

    public function __invoke(
        Trip $trip,
        int $odometerEndKm,
        ?float $engineHoursEnd = null,
        ?int $actorId = null,
        ActorKind $actorKind = ActorKind::User,
    ): Trip {
        // §10.2 has two doors into `completed`: the normal one from `delivered`, and `reopened →
        // completed`, which is the same checklist run a second time after an Owner re-opened the trip.
        // Both are this Action — the point of a re-open is that the facts get re-examined, not that
        // the state is nudged forward — which is why the edge is not in AdvanceTrip's door list.
        if (! in_array($trip->status, [TripStatus::Delivered, TripStatus::Reopened], true)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s; a trip is completed after its POD is approved (§10.2), or re-completed after a re-open.',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        // §10.2: a gate the crossing record has not cleared is a trip that has not finished crossing.
        $uncleared = BorderCrossing::query()
            ->where('trip_id', $trip->id)
            ->whereIn('status', [BorderCrossingStatus::Planned, BorderCrossingStatus::Entered])
            ->orderBy('created_at')
            ->first();

        if ($uncleared !== null) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s cannot be completed: the crossing at %s is %s, and §10.2 requires every crossing to be exited.',
                $trip->trip_no,
                $uncleared->label(),
                $uncleared->status->label(),
            ));
        }

        $start = $trip->odometer_start_km;

        if ($start === null) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s has no opening reading — it never departed, so it cannot be completed.',
                $trip->trip_no,
            ));
        }

        if ($odometerEndKm < $start) {
            throw new InvalidArgumentException(sprintf(
                'The closing reading (%s km) is below the opening one (%s km). §4.3: mileage that runs backwards is recorded as a suspect reading against the truck, not as the end of a trip.',
                number_format($odometerEndKm),
                number_format($start),
            ));
        }

        $recompletion = $trip->status === TripStatus::Reopened;

        return DB::transaction(function () use ($trip, $odometerEndKm, $engineHoursEnd, $start, $recompletion, $actorId, $actorKind): Trip {
            // A first completion records the closing reading and brings the truck home. A
            // *re*-completion after a re-open is the same checklist run again on a trip whose facts
            // were already in: re-issuing the reading would write a second arrival for the same day,
            // and `EndTruckTrip` would refuse a truck that is already back in the yard. So the
            // mileage is only recorded when it changed, and the truck is only touched when it is
            // still out.
            if (! $recompletion || (int) $trip->odometer_end_km !== $odometerEndKm) {
                ($this->recordOdometer)(new OdometerReadingDto(
                    truckId: (int) $trip->truck_id,
                    odometerKm: $odometerEndKm,
                    readOn: now(CurrentCompany::timezone())->toDateString(),
                    source: OdometerSource::TripArrival,
                    engineHours: $engineHoursEnd,
                    tripId: $trip->id,
                ));
            }

            $trip->fill([
                'odometer_end_km' => $odometerEndKm,
                'distance_km_10_2' => $odometerEndKm - $start,
                'completed_at' => $trip->completed_at ?? now(),
            ]);

            $trip->moveTo(TripStatus::Completed, null, [], $actorKind, $actorId);

            if (! $recompletion) {
                ($this->endTruckTrip)((int) $trip->truck_id, 'Returned from trip '.$trip->trip_no);

                // The truck is home, so the reservation that held it must close. §10.1's timeline is
                // append-only — the row stays, with an end time — and without this the truck would sit
                // "already assigned (still open)" forever and no second trip could ever be dispatched
                // on it. The gap was found by a verification run that reused a driver, not by a reader.
                ($this->releaseAssignment)($trip->id);
            }

            return $trip->refresh();
        });
    }
}
