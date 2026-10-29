<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takes a truck off a trip, by id — the counterpart of {@see BeginTruckTrip}.
 *
 * A truck that has come back is `available` again, and the two edges the machine allows from
 * `on_trip` are `available`, `in_workshop` and `out_of_service` (§10.1). Only `available` is offered
 * here, deliberately: routing a returning truck into the workshop is a *workshop* decision, made by a
 * Workshop Manager raising a work order, and letting a trip completion do it would put a maintenance
 * claim in the hands of whoever closed a delivery.
 *
 * A truck that never left — a cancelled or aborted-before-departure trip — is not moved at all: the
 * reason check below refuses it rather than writing an `available → available` move that the
 * operational trail would have to explain.
 */
final class EndTruckTrip
{
    public function __construct(private readonly ChangeTruckStatus $changeStatus) {}

    public function __invoke(int $truckId, string $reason): void
    {
        DB::transaction(function () use ($truckId, $reason): void {
            $truck = Truck::query()->find($truckId);

            if ($truck === null) {
                throw new InvalidArgumentException(sprintf('Truck #%d is not in this company\'s fleet.', $truckId));
            }

            if ($truck->status !== FleetStatus::OnTrip) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s, not on a trip, so there is nothing to return (§10.1).',
                    $truck->label(),
                    $truck->status->label(),
                ));
            }

            ($this->changeStatus)($truck, FleetStatus::Available, $reason);
        });
    }
}
