<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The `awaiting_approval → approved`: somebody with the seat says the spend may happen.
 *
 * The approval is dated onto the row rather than inferred from the trail, because the schema's own
 * control (`work_orders_started_is_approved`) asks the row whether an approver named themselves, and
 * a row that answered "look in the audit log" would be a control the database cannot enforce.
 *
 * `approval_required_amount_base` is deliberately left alone: it records the limit the order was
 * judged by, not the fact that it was approved. Raising the company limit next month must not make
 * this order read as one that never needed approval.
 */
final class ApproveWorkOrder
{
    public function __invoke(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.approve')) {
            throw new InvalidArgumentException('Approving work-order spend is the Owner’s and Operations Manager’s call.');
        }

        if ($actorId === null) {
            throw new InvalidArgumentException('An approval names the person who gave it; there is no actor to record.');
        }

        return DB::transaction(function () use ($workOrder, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::AwaitingApproval) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Approving answers a request that is waiting; it cannot bless an order the machine has already moved.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $locked->forceFill(['approved_by' => $actorId, 'approved_at' => now()])->save();

            $locked->moveTo(
                WorkOrderStatus::Approved,
                null,
                ['estimate_base' => $locked->total_cost_base, 'limit_base' => $locked->approval_required_amount_base],
                ActorKind::User,
                $actorId,
            );

            return $locked->refresh();
        });
    }
}
