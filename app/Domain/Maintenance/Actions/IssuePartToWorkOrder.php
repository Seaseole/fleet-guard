<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Models\Part;
use App\Domain\Maintenance\Models\StockMovement;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\PartCategory;
use App\Domain\Maintenance\StockMovementKind;
use App\Domain\Maintenance\Support\WorkOrderApproval;
use App\Domain\Maintenance\WorkOrderStatus;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Hands a part from the stores shelf to a job, and posts it, and moves the order's total — in one
 * transaction (§15.2 phase 3, §10.6's "parts issue" posting path).
 *
 * Three refusals carry the design. The order must be **sanctioned**: an approved, planned, running or
 * held job may draw parts, while a draft or an estimate still waiting for an approver may not — the
 * shelf should not fund work nobody has blessed. The shelf must **hold** what is asked for, because a
 * store that owes filters is not a number anybody can reconcile. And a part **specific to another
 * truck** is refused by name: a body panel bought for T-01 fitted to T-02 is a mistake, not a
 * transfer.
 *
 * The issue is costed at the shelf's moving average, and an issue that takes the last unit takes the
 * exact remaining value, so the emptied shelf is worth nothing (§20.26's rule, now applied to parts).
 *
 * The order's totals are recomputed with `recalculateCosts(false)` — a running job may not have its
 * *estimate* edited, but a part it actually consumed is a fact. Before the work starts the threshold
 * is re-asked, because an approved estimate that grows past the limit is a spend nobody approved.
 * While the job runs there is no edge back to `awaiting_approval`, and the part has already left the
 * shelf; the spend is recorded as the fact it is.
 */
final class IssuePartToWorkOrder
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly WorkOrderApproval $approval,
    ) {}

    public function __invoke(
        WorkOrder $workOrder,
        Part $part,
        int $quantityX100,
        DateTimeInterface $issuedOn,
        ?string $note = null,
        ?int $actorId = null,
    ): StockMovement {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Issuing a part to a job is a workshop act (§9.3).');
        }

        if ($quantityX100 <= 0) {
            throw new InvalidArgumentException('An issue must take at least one hundredth of a unit off the shelf.');
        }

        $base = CurrentCompany::model()->base_currency_code;

        return DB::transaction(function () use ($workOrder, $part, $quantityX100, $issuedOn, $note, $actorId, $base): StockMovement {
            /** @var WorkOrder $order */
            $order = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            /** @var Part $locked */
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('That part is not in this company any more.');

            $this->assertOrderMayDraw($order);

            if ($locked->truck_id !== null && $locked->truck_id !== $order->truck_id) {
                throw new InvalidArgumentException(sprintf(
                    '%s is specific to %s and this job is on %s. A part bought for one truck is not fitted to another.',
                    $locked->label(),
                    $locked->truck?->label() ?? 'truck #'.$locked->truck_id,
                    $order->truck?->label() ?? 'truck #'.$order->truck_id,
                ));
            }

            if ($quantityX100 > $locked->stock_quantity_x100) {
                throw new InvalidArgumentException(sprintf(
                    '%s has %s on the shelf and this issue asks for %s. Receive stock, or count the shelf first.',
                    $locked->label(),
                    $locked->stockLabel(),
                    number_format($quantityX100 / 100, 2, '.', ''),
                ));
            }

            $value = $locked->costForIssue($quantityX100);

            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    '%s is on the shelf at no value, so issuing it would post nothing. Value the stock through a receipt or a count first.',
                    $locked->label(),
                ));
            }

            $unitCost = intdiv($value * 100 + intdiv($quantityX100, 2), $quantityX100);

            $movement = $locked->recordMovement(
                StockMovementKind::Issue,
                -$quantityX100,
                -$value,
                $issuedOn,
                $order->id,
                $unitCost,
                $note,
                $actorId,
            );

            $this->post($order, $locked, $movement, $value, $issuedOn, $base, $actorId);

            $order->recalculateCosts(false);

            if ($order->status->acceptsLines()) {
                $this->approval->apply($order->refresh(), $actorId);
            }

            return $movement;
        }, 3);
    }

    private function assertOrderMayDraw(WorkOrder $order): void
    {
        $allowed = [
            WorkOrderStatus::Approved,
            WorkOrderStatus::Planned,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::OnHold,
        ];

        if (! in_array($order->status, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s is %s. Parts are issued to a sanctioned job — approve or start it first, and a released vehicle cannot draw stock (§10.6).',
                $order->wo_no,
                strtolower($order->status->label()),
            ));
        }
    }

    private function post(
        WorkOrder $order,
        Part $part,
        StockMovement $movement,
        int $value,
        DateTimeInterface $issuedOn,
        string $base,
        ?int $actorId,
    ): void {
        $accounts = $this->chart->byCode();

        $debitCode = $part->category === PartCategory::Tyres ? '5320' : '5310';
        $debit = $accounts[$debitCode] ?? throw new InvalidArgumentException(sprintf(
            'Account %s is not in this company\'s chart, so the part cannot be posted. Seed the chart first (§6.2).',
            $debitCode,
        ));
        $stock = $accounts['1190'] ?? throw new InvalidArgumentException('Account 1190 Parts stock is not in this company\'s chart. Seed the chart first (§6.2).');

        $amount = Money::ofMinor($value, $base);

        $this->ledger->post(PostingDto::inBaseCurrency(
            description: sprintf(
                '%s · %s × %s',
                $order->wo_no,
                number_format($movement->quantity_delta_x100 / -100, 2, '.', ''),
                $part->label(),
            ),
            postedOn: $issuedOn,
            baseCurrencyCode: $base,
            lines: [
                new LineDto(
                    accountId: $debit->id,
                    side: Side::Debit,
                    base: $amount,
                    note: $part->name,
                    dimensions: ['truck_id' => $order->truck_id, 'work_order_id' => $order->id],
                ),
                new LineDto(accountId: $stock->id, side: Side::Credit, base: $amount),
            ],
            sourceType: 'stock_movement',
            sourceId: $movement->id,
            createdBy: $actorId,
        ));
    }
}
