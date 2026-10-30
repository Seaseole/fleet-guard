<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Maintenance\Data\InspectionDto;
use App\Domain\Maintenance\Models\Inspection;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Operations\Models\Trip;
use App\Domain\People\Models\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Writes one inspection to the register (§4.3 INSPECTION).
 *
 * The DTO has already answered the questions the JSON cannot (every item answered, the verdict agrees
 * with the answers); this Action proves the row's relationships: the truck, the trip, the work order
 * and the driver are all in this company, and the trip and job name *this* truck. A pre-trip sheet
 * attached to yesterday's trip on another vehicle is worse than no sheet, because it reads as
 * evidence.
 *
 * No row is ever updated: a correction is a second inspection, which is what an audit of "was the
 * fleet checked" should count.
 */
final class RecordInspection
{
    public function __invoke(InspectionDto $dto): Inspection
    {
        if (! Gate::allows('inspections.record')) {
            throw new InvalidArgumentException('Recording an inspection is a workshop act (§9.3).');
        }

        return DB::transaction(function () use ($dto): Inspection {
            $truck = Truck::query()->findOrFail($dto->truckId);

            if ($dto->tripId !== null) {
                $trip = Trip::query()->findOrFail($dto->tripId);

                if ($trip->truck_id !== $truck->id) {
                    throw new InvalidArgumentException(sprintf('That trip ran on another truck; an inspection of %s cannot be evidence for it.', $truck->label()));
                }
            }

            if ($dto->workOrderId !== null) {
                $workOrder = WorkOrder::query()->findOrFail($dto->workOrderId);

                if ($workOrder->truck_id !== $truck->id) {
                    throw new InvalidArgumentException(sprintf('%s is a job on another truck; an inspection is recorded against the vehicle it checked.', $workOrder->wo_no));
                }
            }

            if ($dto->driverId !== null) {
                Driver::query()->findOrFail($dto->driverId);
            }

            /** @var Inspection $inspection */
            $inspection = Inspection::create([
                'kind' => $dto->kind,
                'truck_id' => $truck->id,
                'trip_id' => $dto->tripId,
                'work_order_id' => $dto->workOrderId,
                'driver_id' => $dto->driverId,
                'done_at' => $dto->doneAt,
                'result' => $dto->result,
                'checklist' => $dto->checklist(),
                'defects' => $dto->cleanDefects() === [] ? null : $dto->cleanDefects(),
                'photo_document_ids' => $dto->photoDocumentIds === [] ? null : array_values($dto->photoDocumentIds),
            ]);

            return $inspection;
        }, 3);
    }
}
