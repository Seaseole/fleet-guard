<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Maintenance\Data\BreakdownDto;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Operations\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Writes a roadside stop to the register (§4.3 BREAKDOWN).
 *
 * The breakdown is recorded before any repair exists, because the first fact is that the truck is
 * down and where — waiting for a work order would leave the corridor's worst hours undocumented. A
 * trip named on the row must be one this truck ran; the cause and the driver-recovery decision were
 * already made coherent by the DTO.
 */
final class RecordBreakdown
{
    public function __invoke(BreakdownDto $dto): Breakdown
    {
        if (! Gate::allows('breakdowns.record')) {
            throw new InvalidArgumentException('Recording a breakdown is an operations act (§9.3).');
        }

        return DB::transaction(function () use ($dto): Breakdown {
            $truck = Truck::query()->findOrFail($dto->truckId);

            if ($dto->tripId !== null) {
                $trip = Trip::query()->findOrFail($dto->tripId);

                if ($trip->truck_id !== $truck->id) {
                    throw new InvalidArgumentException(sprintf(
                        'That trip ran on another truck; %s cannot have broken down on it.',
                        $truck->label(),
                    ));
                }
            }

            /** @var Breakdown $breakdown */
            $breakdown = Breakdown::create([
                'truck_id' => $truck->id,
                'trip_id' => $dto->tripId,
                'occurred_at' => $dto->occurredAt,
                'location' => $dto->place(),
                'description' => $dto->story(),
                'hours_lost' => $dto->hoursLost,
                'cause' => $dto->cause,
                'recoverable_from_driver' => $dto->recoverableFromDriver,
            ]);

            return $breakdown;
        }, 3);
    }
}
