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
 * §10.6's `on_hold → in_progress`: whatever stopped the job has been dealt with.
 *
 * The hold reason and its note are cleared in the same UPDATE as the state. `work_orders_hold_is_explained`
 * refuses a running order that still carries a reason, so "resume" is also the moment the row stops
 * claiming to wait for parts — leaving the reason behind would make the register's "waiting on parts"
 * count wrong in the direction that costs a dispatcher a phone call.
 */
final class ResumeWorkOrder
{
    public function __invoke(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Resuming a job is a workshop act (§9.3).');
        }

        return DB::transaction(function () use ($workOrder, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::OnHold) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s, so there is nothing to resume.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $locked->hold_reason = null;
            $locked->hold_note = null;

            $locked->moveTo(WorkOrderStatus::InProgress, null, [], ActorKind::User, $actorId);

            return $locked->refresh();
        });
    }
}
