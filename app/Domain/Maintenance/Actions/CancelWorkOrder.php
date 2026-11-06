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
 * The `draft|approved|planned → cancelled`: the job was withdrawn before anyone worked on it.
 *
 * `cancelled` and `rejected` are different words on purpose — a rejection was the spend being refused,
 * a cancellation is an approved job going away (the truck was sold, the fault was misdiagnosed, the
 * supplier took it). Both need the sentence, because the trail is the only thing that distinguishes
 * "we withdrew this" from "we forgot it".
 *
 * The machine itself refuses cancellation once the vehicle is in the workshop; stopping work that has
 * started is `on_hold`, not a quiet deletion of the costs already incurred.
 */
final class CancelWorkOrder
{
    public function __invoke(WorkOrder $workOrder, string $reason, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Withdrawing a work order is a workshop act.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cancelling a work order needs the sentence that explains it.');
        }

        if (mb_strlen($reason) > 160) {
            throw new InvalidArgumentException('Keep the cancellation to 160 characters — it is the sentence, not the file.');
        }

        return DB::transaction(function () use ($workOrder, $reason, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [WorkOrderStatus::Draft, WorkOrderStatus::Approved, WorkOrderStatus::Planned], true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Only work that has not started is cancelled; a job under way is put on hold.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $locked->moveTo(WorkOrderStatus::Cancelled, $reason, [], ActorKind::User, $actorId);

            return $locked->refresh();
        });
    }
}
