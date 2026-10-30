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
 * §10.6's `inspected → closed`: the costs are frozen and the capitalise-vs-expense call is made.
 *
 * Closing is the accounting act the design gives to the seat holding `work_orders.close` (§9.3 — the
 * Owner, Operations Manager or Workshop Manager; §10.6 names the Accountant, a deviation recorded in
 * DESIGN §20.28). The order must be inspected first, so "closed" means somebody also checked the work.
 *
 * **Nothing posts here (§10.6).** Parts post when stock leaves, labour on the timesheet, external work
 * on the supplier bill; `closed` only makes the row read-only. What the close does record is the
 * decision: a `capital_candidate` line is the workshop's opinion, and the person closing is the one
 * who says how much of the spend is an asset. The amount defaults to the flagged lines but may be
 * overridden, and it can never exceed what the order actually cost.
 */
final class CloseWorkOrder
{
    public function __invoke(
        WorkOrder $workOrder,
        bool $capitalise,
        ?int $capitalisedAmountMinor = null,
        ?int $actorId = null,
    ): WorkOrder {
        if (! Gate::allows('work_orders.close')) {
            throw new InvalidArgumentException('Closing a work order freezes its costs; that is the accounting seat’s call (§9.3).');
        }

        return DB::transaction(function () use ($workOrder, $capitalise, $capitalisedAmountMinor, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::Inspected) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Closing follows inspection — the order is not frozen before somebody has seen the work (§10.6).',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $amount = $capitalise ? $this->capitalAmount($locked, $capitalisedAmountMinor) : 0;

            $locked->capitalised = $capitalise;
            $locked->capitalised_amount_base = $amount;
            $locked->closed_at = now();

            $locked->moveTo(
                WorkOrderStatus::Closed,
                null,
                ['total_base' => $locked->total_cost_base, 'capitalised_base' => $amount],
                ActorKind::User,
                $actorId,
            );

            return $locked->refresh();
        });
    }

    /**
     * How much of the spend is capitalised: the closing person's figure, or the flagged lines' sum.
     */
    private function capitalAmount(WorkOrder $workOrder, ?int $requested): int
    {
        if ($requested !== null) {
            if ($requested <= 0) {
                throw new InvalidArgumentException('A capitalised amount must be above zero; close the order un-capitalised instead.');
            }

            if ($requested > $workOrder->total_cost_base) {
                throw new InvalidArgumentException(sprintf(
                    'A capitalised amount cannot exceed what the order cost (%s).',
                    $workOrder->total()->format(),
                ));
            }

            return $requested;
        }

        $flagged = 0;

        foreach ($workOrder->costLines() as $line) {
            if ($line->capital_candidate) {
                $flagged += $line->base_amount;
            }
        }

        if ($flagged <= 0) {
            throw new InvalidArgumentException('Nothing on this order was marked as a capital candidate, and no amount was given — there is nothing to capitalise.');
        }

        if ($flagged > $workOrder->total_cost_base) {
            // Cannot happen while the totals are the sum of the lines; stated so a future edit to
            // `recalculateCosts()` fails here rather than at the CHECK with no explanation.
            throw new InvalidArgumentException('The flagged lines exceed the order total; recompute the costs before closing.');
        }

        return $flagged;
    }
}
