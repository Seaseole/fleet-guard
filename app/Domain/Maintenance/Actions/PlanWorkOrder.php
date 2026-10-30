<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * §10.6's `approved → planned`: the job has a day in the workshop diary.
 *
 * The date is stored on the row (`planned_for`) because "when is the truck in the bay" is a question
 * the fleet register asks of a truck that is still on the road, and answering it from the audit trail
 * would mean parsing event context to draw a calendar.
 *
 * A past date is allowed: backdating the diary is how a workshop records that the planning happened
 * late, and refusing it would push the real date out of the row.
 */
final class PlanWorkOrder
{
    public function __invoke(WorkOrder $workOrder, string $plannedFor, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Planning a work order is a workshop act (§9.3).');
        }

        try {
            $date = Carbon::parse($plannedFor)->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException('A planned date must be a date — the workshop diary cannot schedule "soon".');
        }

        return DB::transaction(function () use ($workOrder, $date, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::Approved) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. A job is planned once it is approved, so the diary never holds work nobody may do (§10.6).',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            // Set on the model so `moveTo` writes the date and the state in one UPDATE.
            $locked->planned_for = $date->toDateString();

            $locked->moveTo(
                WorkOrderStatus::Planned,
                null,
                ['planned_for' => $date->toDateString()],
                ActorKind::User,
                $actorId,
            );

            return $locked->refresh();
        });
    }
}
