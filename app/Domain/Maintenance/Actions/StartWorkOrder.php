<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Actions\ChangeTruckStatus;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The `approved|planned → in_progress`: the vehicle is in the bay.
 *
 * Two things happen and they belong together: the order starts, and the truck stops being available
 * to dispatch. Leaving the second to a person is how a truck gets booked onto a trip while its brakes
 * are out — the fleet register reads `in_workshop` from the moment the work starts, and the dispatch
 * picker follows it.
 *
 * The approval rule is re-asserted here rather than trusted to the state: The control is about
 * spend, and an order whose estimate crossed the limit after its last decision is `awaiting_approval`
 * by the machine's own logic — but an Action that only read the status would wave through the first
 * caller that moved it back along the wrong edge.
 */
final class StartWorkOrder
{
    public function __construct(private readonly ChangeTruckStatus $changeStatus) {}

    public function __invoke(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Putting a vehicle in the workshop is a workshop act.');
        }

        return DB::transaction(function () use ($workOrder, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::Approved && $locked->status !== WorkOrderStatus::Planned) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Work starts from `approved` or `planned`.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            if ($locked->neededApproval() && $locked->approved_by === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s is estimated above the approval limit and nobody holding `work_orders.approve` has said yes — the workshop cannot spend it.',
                    $locked->wo_no,
                ));
            }

            $locked->moveTo(WorkOrderStatus::InProgress, null, [], ActorKind::User, $actorId);

            $truck = $locked->truck;

            if ($truck !== null
                && $truck->status !== FleetStatus::InWorkshop
                && $truck->canTransitionTo(FleetStatus::InWorkshop)) {
                ($this->changeStatus)($truck, FleetStatus::InWorkshop);
            }

            return $locked->refresh();
        });
    }
}
