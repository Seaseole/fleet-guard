<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Queries\DriverDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Names the equipment, the crew and the date — the `draft → planned`.
 *
 * This is the first state that makes a promise, so it is the first state that can be refused for a
 * reason a person can fix: the truck or driver must exist in *this* company (a guessed id from
 * another tenant reads as "no such truck", never as a silent cross-tenant row), and the departure
 * must be a real instant.
 *
 * Neither the availability nor the papers question is asked here. A truck in the workshop on Monday
 * may legitimately be planned for Friday, and a licence that lapses next month is a fact the planner
 * should see on the board rather than be blocked by. The design puts both tests on `planned → assigned`,
 * where the commitment is actually made — that is {@see DispatchTrip}'s job and the reason the two
 * Actions are not one.
 */
final class PlanTrip
{
    public function __construct(
        private readonly FleetDirectory $fleet,
        private readonly DriverDirectory $drivers,
    ) {}

    public function __invoke(Trip $trip, TripDto $dto, ?int $actorId = null): Trip
    {
        return DB::transaction(function () use ($trip, $dto, $actorId): Trip {
            // Locked first, because this writes the truck and the driver the dispatch then commits. A
            // plan saved from a stale dialog used to re-point a trip that had already been assigned:
            // the reservation stayed on the old vehicle while the trip row named a new one.
            /** @var Trip $locked */
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [TripStatus::Draft, TripStatus::Planned, TripStatus::OnHold], true)) {
                $remedy = $locked->status->isPreDeparture()
                    ? 'Put it on hold to free the truck and the driver, then plan it again.'
                    : 'It has already left, so its crew cannot be changed from here.';

                throw new InvalidArgumentException(sprintf(
                    'Trip %s is %s. %s',
                    $locked->trip_no,
                    $locked->status->label(),
                    $remedy,
                ));
            }

            $truck = $this->fleet->truck($dto->truckId)
                ?? throw new InvalidArgumentException(sprintf('Truck #%d is not in this company\'s fleet.', $dto->truckId));

            $driver = $this->drivers->find($dto->driverId)
                ?? throw new InvalidArgumentException(sprintf('Driver #%d is not in this company.', $dto->driverId));

            if ($dto->trailerId !== null && $this->fleet->trailer($dto->trailerId) === null) {
                throw new InvalidArgumentException(sprintf('Trailer #%d is not in this company\'s fleet.', $dto->trailerId));
            }

            $planned = Carbon::parse($dto->plannedDepartureAt)->timezone(CurrentCompany::timezone());

            $locked->fill([
                'truck_id' => $truck->id,
                'trailer_id' => $dto->trailerId,
                'driver_id' => $driver->id,
                'supercargo_employee_id' => $dto->supercargoEmployeeId,
                'planned_departure_at' => $planned,
            ]);

            if ($locked->status === TripStatus::Draft) {
                $locked->moveTo(TripStatus::Planned, null, [], ActorKind::User, $actorId);
            }

            $locked->save();

            return $locked->refresh();
        });
    }
}
