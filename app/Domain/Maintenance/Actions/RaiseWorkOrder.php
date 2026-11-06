<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\Support\WorkOrderApproval;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Raises a work order and puts it where the design says it belongs: `draft`, then straight to `approved`
 * or `awaiting_approval` depending on its estimate against the limit.
 *
 * The estimate is the lines' own arithmetic, not a number typed into a box, so the threshold is
 * measuring the same figure the workshop will be paid for. A truck that is sold cannot be taken into
 * the workshop, and a schedule, trip or supplier named on the order must belong to this company and
 * (for the schedule and trip) to this truck — the alternative is a service history that credits the
 * wrong vehicle.
 *
 * `draft` is the row's first state because the machine starts there and a future "save and come
 * back" path needs it; this Action always finishes the job of deciding, so a caller never sees the
 * draft row.
 */
final class RaiseWorkOrder
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly WorkOrderApproval $approval,
    ) {}

    public function __invoke(WorkOrderDto $dto, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Raising a work order is a workshop act.');
        }

        return DB::transaction(function () use ($dto, $actorId): WorkOrder {
            $truck = Truck::query()->findOrFail($dto->truckId);

            if (! $truck->active || $truck->status === FleetStatus::Sold) {
                throw new InvalidArgumentException(sprintf('%s has left the fleet and cannot be taken into the workshop.', $truck->label()));
            }

            $base = CurrentCompany::model()->base_currency_code;

            foreach ($dto->lines as $line) {
                if ($line->currencyCode !== $base) {
                    throw new InvalidArgumentException(sprintf(
                        'A work-order line is priced in %s. A line quoted in %s arrives with the supplier bill, which carries its rate (Phase 5).',
                        $base,
                        $line->currencyCode,
                    ));
                }
            }

            $this->assertBelongs($dto, $truck);

            /** @var WorkOrder $workOrder */
            $workOrder = WorkOrder::create([
                'wo_no' => $this->numbers->allocate('work_order', null, $actorId),
                'truck_id' => $truck->id,
                'trailer_id' => $dto->trailerId,
                'trip_id' => $dto->tripId,
                'service_schedule_id' => $dto->serviceScheduleId,
                'kind' => $dto->kind,
                'status' => WorkOrderStatus::Draft,
                'title' => $dto->title,
                'description' => $dto->description,
                'estimated_hours_10_2' => $dto->estimatedHours,
                'base_currency_code' => $base,
                'opened_on' => Carbon::now(CurrentCompany::timezone())->toDateString(),
                'mechanic_employee_id' => $dto->mechanicEmployeeId,
                'supplier_id' => $dto->supplierId,
                'raised_by' => $actorId,
            ]);

            foreach ($dto->lines as $line) {
                WorkOrderLine::create([
                    'work_order_id' => $workOrder->id,
                    'kind' => $line->kind,
                    'description' => $line->description,
                    'quantity_x100' => $line->quantityX100,
                    'unit_cost_minor' => $line->unitCostMinor,
                    'currency_code' => $line->currencyCode,
                    'base_amount' => $line->baseAmount(),
                    'part_id' => $line->partId,
                    'capital_candidate' => $line->capitalCandidate,
                ]);
            }

            $workOrder->refresh()->recalculateCosts();

            return $this->approval->apply($workOrder->refresh(), $actorId);
        }, 3);
    }

    private function assertBelongs(WorkOrderDto $dto, Truck $truck): void
    {
        if ($dto->serviceScheduleId !== null) {
            $schedule = ServiceSchedule::query()->findOrFail($dto->serviceScheduleId);

            if ($schedule->truck_id !== $truck->id) {
                throw new InvalidArgumentException(sprintf('"%s" is a schedule for another truck; a work order cannot close it.', $schedule->name));
            }
        }

        if ($dto->tripId !== null) {
            $trip = Trip::query()->findOrFail($dto->tripId);

            if ($trip->truck_id !== null && $trip->truck_id !== $truck->id) {
                throw new InvalidArgumentException(sprintf('%s is not the truck on %s — a breakdown is recorded against the trip’s own truck.', $truck->label(), $trip->trip_no));
            }
        }

        if ($dto->mechanicEmployeeId !== null) {
            Employee::query()->findOrFail($dto->mechanicEmployeeId);
        }

        if ($dto->supplierId !== null) {
            Supplier::query()->findOrFail($dto->supplierId);
        }
    }
}
