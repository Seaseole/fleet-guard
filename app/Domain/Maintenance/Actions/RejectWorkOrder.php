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
 * The `awaiting_approval → rejected`: the owner looked at the estimate and said no.
 *
 * The reason is mandatory — the row and `operational_events` both carry it — because a rejected
 * estimate with no sentence tells the workshop nothing about what to do differently, and the truck
 * that needed the work is still on the yard.
 *
 * `rejected_by` does not exist as a column: unlike the approval, nothing later gates on who said no,
 * and the trail already names them.
 */
final class RejectWorkOrder
{
    public function __invoke(WorkOrder $workOrder, string $reason, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.approve')) {
            throw new InvalidArgumentException('Rejecting an estimate is the same seat that approves one.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A rejection needs the sentence that explains it — the workshop reads it before re-quoting.');
        }

        if (mb_strlen($reason) > 160) {
            throw new InvalidArgumentException('Keep the rejection to 160 characters — it is the sentence, not the file.');
        }

        return DB::transaction(function () use ($workOrder, $reason, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::AwaitingApproval) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s, so there is no request to reject.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $locked->moveTo(WorkOrderStatus::Rejected, $reason, [], ActorKind::User, $actorId);

            return $locked->refresh();
        });
    }
}
