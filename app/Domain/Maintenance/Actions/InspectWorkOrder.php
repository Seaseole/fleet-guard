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
 * §10.6's `completed → inspected`: the safety-critical work has had its second pair of eyes.
 *
 * A person rather than an automatic step, because the check being evidencable is the point — "the
 * brakes were signed off before the truck left" is a sentence someone has to be able to say. The
 * inspector is in the trail; there is no column, because nothing downstream gates on their identity.
 */
final class InspectWorkOrder
{
    public function __invoke(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Inspecting a job is a workshop act (§9.3).');
        }

        return DB::transaction(function () use ($workOrder, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::Completed) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Inspection follows the release of the vehicle (§10.6).',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $locked->moveTo(WorkOrderStatus::Inspected, null, [], ActorKind::User, $actorId);

            return $locked->refresh();
        });
    }
}
