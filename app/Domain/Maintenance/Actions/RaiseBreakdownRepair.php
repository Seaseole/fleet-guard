<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Raises the repair a breakdown finally got, and ties the two rows together (§4.3 BREAKDOWN).
 *
 * The work order is raised by {@see RaiseWorkOrder}, so the approval threshold, the numbering and the
 * line arithmetic are the same ones a workshop-raised job goes through — a breakdown is not a way to
 * skip the owner's approval, it is a reason to need it sooner. This Action only adds the facts the
 * register knows: the job is a `breakdown` kind, it is on the breakdown's truck and trip, and a
 * breakdown gets one repair — a second job is a second breakdown.
 */
final class RaiseBreakdownRepair
{
    public function __construct(private readonly RaiseWorkOrder $raise) {}

    public function __invoke(Breakdown $breakdown, WorkOrderDto $dto, ?int $actorId = null): WorkOrder
    {
        return DB::transaction(function () use ($breakdown, $dto, $actorId): WorkOrder {
            /** @var Breakdown $locked */
            $locked = Breakdown::query()->whereKey($breakdown->id)->lockForUpdate()->firstOrFail();

            if ($locked->work_order_id !== null) {
                $existing = WorkOrder::query()->find($locked->work_order_id);

                throw new InvalidArgumentException(sprintf(
                    'This breakdown already has its repair (%s).',
                    $existing?->wo_no ?? 'a work order',
                ));
            }

            if ($dto->kind !== WorkOrderKind::Breakdown) {
                throw new InvalidArgumentException('The job raised from a breakdown is a breakdown work order; the register groups repairs by why the truck stopped.');
            }

            if ($dto->truckId !== $locked->truck_id) {
                throw new InvalidArgumentException('The repair must be raised on the truck that broke down.');
            }

            if ($dto->tripId !== null && $dto->tripId !== $locked->trip_id) {
                throw new InvalidArgumentException('The repair names a different trip from the breakdown; the job is charged to the run that stopped.');
            }

            $workOrder = ($this->raise)($dto, $actorId);

            $locked->forceFill(['work_order_id' => $workOrder->id])->save();

            return $workOrder;
        }, 3);
    }
}
