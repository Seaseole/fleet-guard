<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\Support\WorkOrderApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Adds a cost line to a work order that has not been started (§10.6).
 *
 * The line is allowed while the order is draft, awaiting approval, approved or planned, because that
 * is when an estimate is still an estimate. Once a mechanic is under the truck the costs are what the
 * work took, and phase 3e's stock issues and phase 5's bills are what will write them.
 *
 * Adding a line can cross the approval limit, so the threshold is re-asked after the totals move: an
 * approved 4,000 order that gains a 3,000 line is a 7,000 order nobody approved, and §10.6 says it
 * cannot be worked until somebody does.
 */
final class AddWorkOrderLine
{
    public function __construct(private readonly WorkOrderApproval $approval) {}

    public function __invoke(WorkOrder $workOrder, WorkOrderLineDto $line, ?int $actorId = null): WorkOrderLine
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Adding a work-order line is a workshop act (§9.3).');
        }

        return DB::transaction(function () use ($workOrder, $line, $actorId): WorkOrderLine {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->acceptsLines()) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s: its lines are fixed once the vehicle is in the workshop. Record the extra cost through the path that incurred it (§10.6).',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            $base = CurrentCompany::model()->base_currency_code;

            if ($line->currencyCode !== $base) {
                throw new InvalidArgumentException(sprintf(
                    'A work-order line is priced in %s. A line quoted in %s arrives with the supplier bill, which carries its §5.2 rate (Phase 5).',
                    $base,
                    $line->currencyCode,
                ));
            }

            /** @var WorkOrderLine $created */
            $created = WorkOrderLine::create([
                'work_order_id' => $locked->id,
                'kind' => $line->kind,
                'description' => $line->description,
                'quantity_x100' => $line->quantityX100,
                'unit_cost_minor' => $line->unitCostMinor,
                'currency_code' => $line->currencyCode,
                'base_amount' => $line->baseAmount(),
                'part_id' => $line->partId,
                'capital_candidate' => $line->capitalCandidate,
            ]);

            $locked->refresh()->recalculateCosts();
            $this->approval->apply($locked->refresh(), $actorId);

            return $created;
        }, 3);
    }
}
