<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\Support\WorkOrderApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Removes a cost line from a work order that has not been started.
 *
 * A removed estimate is not a reversed cost — nothing was ever posted — so the row is deleted and the
 * totals are recomputed. If the removal brings the estimate back under the approval limit while the
 * order is waiting, the order is approved by the same rule that would have approved it when raised;
 * the approval version of the move is recorded with its reason, so the trail does not claim somebody
 * with `work_orders.approve` decided.
 */
final class RemoveWorkOrderLine
{
    public function __construct(private readonly WorkOrderApproval $approval) {}

    public function __invoke(WorkOrder $workOrder, WorkOrderLine $line, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Editing a work order’s lines is a workshop act.');
        }

        return DB::transaction(function () use ($workOrder, $line, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->acceptsLines()) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s: its lines are fixed once the vehicle is in the workshop.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            if ($line->work_order_id !== $locked->id) {
                throw new InvalidArgumentException('That line belongs to a different work order.');
            }

            $line->delete();

            $locked->refresh()->recalculateCosts();

            return $this->approval->apply($locked->refresh(), $actorId);
        }, 3);
    }
}
