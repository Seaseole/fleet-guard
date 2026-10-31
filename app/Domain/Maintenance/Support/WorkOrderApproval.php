<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Support;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;

/**
 * §10.6's threshold, applied to whatever the order currently totals.
 *
 * The rule is one sentence — an order whose estimate is above the limit needs an approver before it
 * can be worked — but it has to be asked in three places (raise, add a line, remove one), and asking
 * it in three places is how a control drifts into two answers. The limit is read from the company's
 * settings once per call: an owner who lowers it next month wants the next order judged by the new
 * figure, while the rows already raised keep the number they were judged by (§10.6's copy-onto-the-row
 * rule).
 *
 * An order an approver has already looked at is left alone. The approver approved a job, not a
 * sentence in a config file, and re-dating their approval because a line moved would make the audit
 * trail say they approved something they never saw.
 */
final class WorkOrderApproval
{
    public function __construct(private readonly CompanySettings $settings) {}

    public function limit(): int
    {
        return max(0, (int) $this->settings->get('maintenance.work_order_approval_threshold_base'));
    }

    /**
     * Bring the order's approval state in line with its total, moving it only along edges §10.6 has.
     */
    public function apply(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        if ($workOrder->approved_by !== null) {
            return $workOrder;
        }

        $limit = $this->limit();
        $over = $limit > 0 && $workOrder->total_cost_base > $limit;

        if ($over) {
            if ($workOrder->approval_required_amount_base !== $limit) {
                $workOrder->forceFill(['approval_required_amount_base' => $limit])->save();
            }

            if ($workOrder->status === WorkOrderStatus::Draft
                || $workOrder->status === WorkOrderStatus::Approved
                || $workOrder->status === WorkOrderStatus::Planned) {
                $workOrder->moveTo(
                    WorkOrderStatus::AwaitingApproval,
                    null,
                    ['estimate_base' => $workOrder->total_cost_base, 'limit_base' => $limit],
                    ActorKind::User,
                    $actorId,
                );
            }

            return $workOrder->refresh();
        }

        if ($workOrder->approval_required_amount_base !== 0) {
            $workOrder->forceFill(['approval_required_amount_base' => 0])->save();
        }

        // The estimate fell under the limit — either it was raised there, or a line was taken off
        // before anyone decided. The order is approved by the rule itself, exactly as if it had been
        // raised at that figure. `draft` is included because a threshold row that the owner lowered
        // *below* every order would otherwise leave new work sitting in a state nobody can act on.
        if ($workOrder->status === WorkOrderStatus::Draft
            || $workOrder->status === WorkOrderStatus::AwaitingApproval) {
            $workOrder->moveTo(
                WorkOrderStatus::Approved,
                null,
                ['estimate_base' => $workOrder->total_cost_base, 'limit_base' => $limit],
                ActorKind::User,
                $actorId,
            );
        }

        return $workOrder->refresh();
    }
}
