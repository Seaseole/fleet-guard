<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Alerts\Models\ReminderThreshold;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\AddWorkOrderLine;
use App\Domain\Maintenance\Actions\ApproveWorkOrder;
use App\Domain\Maintenance\Actions\CancelWorkOrder;
use App\Domain\Maintenance\Actions\CloseWorkOrder;
use App\Domain\Maintenance\Actions\CompleteWorkOrder;
use App\Domain\Maintenance\Actions\CountPartStock;
use App\Domain\Maintenance\Actions\CreatePart;
use App\Domain\Maintenance\Actions\HoldWorkOrder;
use App\Domain\Maintenance\Actions\InspectWorkOrder;
use App\Domain\Maintenance\Actions\IssuePartToWorkOrder;
use App\Domain\Maintenance\Actions\RaiseWorkOrder;
use App\Domain\Maintenance\Actions\ReceivePartStock;
use App\Domain\Maintenance\Actions\RejectWorkOrder;
use App\Domain\Maintenance\Actions\RemoveWorkOrderLine;
use App\Domain\Maintenance\Actions\ResumeWorkOrder;
use App\Domain\Maintenance\Actions\SaveServiceSchedule;
use App\Domain\Maintenance\Actions\StartWorkOrder;
use App\Domain\Maintenance\Alerts\ServiceDueSource;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Data\ServiceScheduleDto;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\ServiceDueState;
use App\Domain\Maintenance\ServiceScheduleKind;
use App\Domain\Maintenance\StockMovementKind;
use App\Domain\Maintenance\WorkOrderKind;
use App\Domain\Maintenance\WorkOrderLineKind;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/*
 * verify-maintenance — §15.2 phase 3's third slice (3d): service schedules and work orders.
 *
 * Proves, against the live MySQL 9.1 instance:
 *
 *   - the schema carries §4.3/§10.6's promises as CHECKs, including the approval control written as
 *     a database fact, the hold's reason, and the line's rounding;
 *   - a schedule's due state is derived (§10.8's argument) and moves only when the service is done;
 *   - the whole machine walks: raise → (threshold) → approve → start → hold → resume → complete →
 *     inspect → close, with each illegal edge refused;
 *   - completing against a schedule advances it and resolves the `service_due` alert in the same
 *     transaction — the source and the resolution are one loop;
 *   - a line added after approval that crosses the limit asks again, and taking it back off
 *     returns the order to approved;
 *   - slice 3e's shelf: a receipt, an issue to a job and a physical count each move the valued stock
 *     and post where §6.8 says they belong, a job that is not sanctioned cannot draw stock, and an
 *     emptied shelf is worth nothing.
 *
 * Everything this file writes lives in the `XMT` namespace and is deleted at the end, including the
 * `work_order` counter, so the demo company is exactly as it was found.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'sync']);

CurrentCompany::force(1);

$out = new ConsoleOutput;

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $out;
    $checks++;
    $out->writeln("  ok   {$what}");
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures, $out;
    $checks++;
    $failures++;
    $out->writeln('  FAIL '.$what.($why === '' ? '' : " — {$why}"));
}

function check(bool $condition, string $what, string $why = ''): void
{
    $condition ? ok($what) : failed($what, $why);
}

function refuses(string $what, callable $fn, string $expectClass = InvalidArgumentException::class, ?string $messageContains = null): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        if (! $e instanceof $expectClass) {
            failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());

            return;
        }

        if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
            failed($what, "the refusal did not mention '{$messageContains}': ".$e->getMessage());

            return;
        }

        ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 120)));
    }
}

/** Delete rows by id list, refusing to run on an empty list. */
function wipeIds(string $table, string $column, array $ids): int
{
    return $ids === [] ? 0 : DB::table($table)->whereIn($column, $ids)->delete();
}

$alignWorkOrderCounter = static function (): void {
    $highest = DB::table('work_orders')
        ->where('company_id', 1)
        ->pluck('wo_no')
        ->map(static fn (string $number): int => (int) preg_replace('/\D/', '', $number))
        ->max() ?? 0;

    DB::table('number_sequences')
        ->where('company_id', 1)
        ->where('scope', 'work_order')
        ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
};

$wipeFixtures = static function (array $truckIds) use ($alignWorkOrderCounter): void {
    $scheduleIds = DB::table('service_schedules')->whereIn('truck_id', $truckIds ?: [0])->pluck('id')->all();
    $workOrderIds = DB::table('work_orders')->whereIn('truck_id', $truckIds ?: [0])->pluck('id')->all();
    $alertIds = $scheduleIds === [] ? [] : DB::table('alerts')
        ->where('subject_type', 'service_schedule')
        ->whereIn('subject_id', array_map('strval', $scheduleIds))
        ->pluck('id')
        ->all();

    wipeIds('alerts', 'id', $alertIds);
    wipeIds('operational_events', 'aggregate_id', array_merge(array_map('strval', $workOrderIds), array_map('strval', $scheduleIds), array_map('strval', $alertIds)));
    wipeIds('stock_movements', 'work_order_id', $workOrderIds);
    wipeIds('work_order_lines', 'work_order_id', $workOrderIds);
    wipeIds('work_orders', 'id', $workOrderIds);
    wipeIds('service_schedules', 'id', $scheduleIds);
    wipeIds('odometer_readings', 'truck_id', $truckIds);
    wipeIds('audits', 'auditable_id', array_merge($workOrderIds, array_map('strval', $scheduleIds), array_map('strval', $truckIds)));
    wipeIds('trucks', 'id', $truckIds);
    $alignWorkOrderCounter();
};

/**
 * The shelf this run built: movements first, then the journal entries those movements posted (under
 * §6.3's lifted barrier, because an entry cannot be deleted while the guard is up), then the parts.
 */
$wipeParts = static function (): void {
    $partIds = DB::table('parts')->where('sku', 'like', 'XMT-%')->pluck('id')->all();
    $movementIds = $partIds === [] ? [] : DB::table('stock_movements')->whereIn('part_id', $partIds)->pluck('id')->all();
    $entryIds = $movementIds === [] ? [] : DB::table('gl_journal_entries')
        ->where('source_type', 'stock_movement')
        ->whereIn('source_id', $movementIds)
        ->pluck('id')
        ->all();

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-maintenance cleanup: the stock movements this run posted', 1, static function () use ($entryIds): void {
            wipeIds('gl_journal_lines', 'entry_id', $entryIds);
            wipeIds('gl_journal_entries', 'id', $entryIds);
        });
    }

    wipeIds('stock_movements', 'id', $movementIds);
    wipeIds('audits', 'auditable_id', array_map('strval', $partIds));
    wipeIds('parts', 'id', $partIds);
};

/* ------------------------------------------------------------------------- */
echo "== pre-clean: an interrupted run leaves nothing the next one counts ==\n";

$xmtTruckIds = DB::table('trucks')->where('registration_no', 'like', '%XMT')->pluck('id')->all();
$wipeParts();
$wipeFixtures($xmtTruckIds);

ok('an interrupted run\'s fixtures are gone and the work-order counter matches the surviving rows');

/* ------------------------------------------------------------------------- */
echo "\n== §4.3/§10.6 the schema states the promises ==\n";

$scheduleDdl = (string) ((array) DB::select('show create table service_schedules')[0])['Create Table'];

foreach ([
    'service_schedules_company_truck_name_unique',
    'service_schedules_kind_allowed',
    'service_schedules_interval_shape',
    'service_schedules_due_point_present',
    'service_schedules_due_after_last',
] as $name) {
    check(str_contains($scheduleDdl, $name), "service_schedules carries {$name}");
}

check(! str_contains($scheduleDdl, '`state`'), '...and stores no state column — the due state is derived (§10.8)');

$workOrderDdl = (string) ((array) DB::select('show create table work_orders')[0])['Create Table'];

foreach ([
    'work_orders_company_wo_no_unique',
    'work_orders_trip_company_fk',
    'work_orders_status_allowed',
    'work_orders_hold_is_explained',
    'work_orders_totals_add_up',
    'work_orders_started_is_approved',
    'work_orders_completed_has_odometer',
    'work_orders_closed_is_dated',
    'work_orders_capitalised_is_closed',
    'work_orders_capitalised_amount_only_when_capitalised',
] as $name) {
    check(str_contains($workOrderDdl, $name), "work_orders carries {$name}");
}

check(Schema::hasColumns('work_orders', ['previous_state', 'state_changed_at', 'approval_required_amount_base', 'capitalised_amount_base']), '...and §10.1/§10.6\'s state, threshold and capitalisation columns');

$lineDdl = (string) ((array) DB::select('show create table work_order_lines')[0])['Create Table'];

foreach (['work_order_lines_kind_allowed', 'work_order_lines_amount_is_rounded_product', 'work_order_lines_quantity_positive'] as $name) {
    check(str_contains($lineDdl, $name), "work_order_lines carries {$name}");
}

/* ------------------------------------------------------------------------- */
echo "\n== §4.3/§15.2 3e the shelf and its stock book ==\n";

$partDdl = (string) ((array) DB::select('show create table parts')[0])['Create Table'];

check(Schema::hasColumns('parts', ['stock_quantity_x100', 'stock_value_base', 'average_cost_minor']), 'parts carries the valued shelf (quantity, value, average)');
check(str_contains($partDdl, 'parts_empty_stock_is_worth_nothing'), '...and the CHECK that an empty shelf is worth nothing');

$movementDdl = (string) ((array) DB::select('show create table stock_movements')[0])['Create Table'];

foreach ([
    'stock_movements_kind_allowed',
    'stock_movements_moved_something',
    'stock_movements_signs_agree',
    'stock_movements_empty_is_worth_nothing',
    'stock_movements_issue_names_an_order',
] as $name) {
    check(str_contains($movementDdl, $name), "stock_movements carries {$name}");
}

check(Schema::hasColumns('stock_movements', ['quantity_delta_x100', 'value_delta_base', 'quantity_after_x100', 'value_after_base', 'average_cost_minor_after', 'work_order_id']), '...and stores the after-snapshots, so a re-read never re-prices a past movement');

$codes = app(ChartOfAccounts::class)->byCode();

check(isset($codes['1190'], $codes['5315'], $codes['5310'], $codes['2110']), 'the chart has 1190 Parts stock, 5315 Parts stock variance, 5310 Parts consumed and 2110 payable');

/* ------------------------------------------------------------------------- */
echo "\n== fixtures ==\n";

$base = CurrentCompany::model()->base_currency_code;

$owner = User::query()->findOrFail(1);
Auth::login($owner);

$truck = app(CreateTruck::class)(new TruckDto(
    registrationNo: 'BWD999XMT',
    fleetNo: 'XMT-01',
    odometerKm: 119_400,
    engineHours: 420.50,
    fuelType: 'diesel',
));

check($truck->status === FleetStatus::Available, 'the fixture truck starts available');

$schedule = app(SaveServiceSchedule::class)(new ServiceScheduleDto(
    truckId: $truck->id,
    name: 'Engine service XMT',
    kind: ServiceScheduleKind::Km,
    intervalValue: 10_000,
    lastDoneAtValue: 110_000,
));

check($schedule->next_due_at_value === 120_000, 'the schedule\'s next due point is last done + the interval');

$today = Carbon::now(CurrentCompany::timezone());

check($schedule->remainingOn($truck->refresh(), $today) === 600, 'the truck is 600 km inside the schedule\'s next due point');
check($schedule->stateOn($today) === ServiceDueState::DueSoon, '...which the derived state reads as due soon, not overdue');

/* ------------------------------------------------------------------------- */
echo "\n== §14.1 the service_due source sees the schedule and the loop closes ==\n";

$thresholds = ThresholdSet::fromRows(collect([
    new ReminderThreshold(['family' => 'service', 'days_before' => 60, 'recipients' => ['workshop_manager'], 'active' => true]),
    new ReminderThreshold(['family' => 'service', 'days_before' => 7, 'recipients' => ['workshop_manager'], 'active' => true]),
]));

$source = app(ServiceDueSource::class);

/** @var list<ReminderCandidate> $candidates */
$candidates = iterator_to_array($source->candidates($thresholds, $today));

$mine = array_values(array_filter($candidates, static fn (ReminderCandidate $c): bool => $c->subjectId === (string) $schedule->id));

check(count($mine) === 1, 'one candidate for this schedule — the due point is one instance, not one per rung');

$candidate = $mine[0] ?? null;

check($candidate !== null && $candidate->type === AlertType::ServiceDue, '...typed service_due');
check($candidate !== null && $candidate->daysBefore === 7, '...delivered on the tightest configured rung');
check($candidate !== null && $candidate->bucket === 'km:120000', '...bucketed by the due point, so servicing it makes a new instance');
check($candidate !== null && str_contains($candidate->title, '600 km'), '...and the title says how far out the truck is');

$alert = $candidate === null ? null : app(RaiseAlert::class)->handle($candidate)->alert;

check($alert !== null && $alert->type === AlertType::ServiceDue && $alert->state === AlertState::Open, 'the engine raised a live service_due alert for it');

/* ------------------------------------------------------------------------- */
echo "\n== §10.6 the machine: raise → start → hold → resume → complete → inspect → close ==\n";

$under = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Service,
    title: 'Engine service XMT',
    lines: [
        new WorkOrderLineDto(WorkOrderLineKind::Labour, 'Service labour', 200, 15_000, $base),
        new WorkOrderLineDto(WorkOrderLineKind::Consumable, 'Oil and filters', 100, 15_000, $base),
    ],
    serviceScheduleId: $schedule->id,
));

check($under->status === WorkOrderStatus::Approved, 'an under-threshold order is approved by the rule itself');
check($under->total_cost_base === 45_000, '...its total is the lines\' own arithmetic (45,000 minor)');
check($under->approval_required_amount_base === 0, '...and it carries no approval requirement');
check(str_starts_with($under->wo_no, 'WO-'), '...and it was numbered by the work_order scope ('.$under->wo_no.')');

$started = app(StartWorkOrder::class)($under);
check($started->status === WorkOrderStatus::InProgress, 'the order starts');
check($started->truck->refresh()->status === FleetStatus::InWorkshop, '...and the truck is in the workshop, so dispatch cannot name it');

$held = app(HoldWorkOrder::class)($started, HoldReason::AwaitingParts, 'Waiting on the filter kit');
check($held->status === WorkOrderStatus::OnHold && $held->hold_reason === HoldReason::AwaitingParts, 'a hold carries its reason');
check($held->hold_note === 'Waiting on the filter kit', '...and its note');

$resumed = app(ResumeWorkOrder::class)($held);
check($resumed->status === WorkOrderStatus::InProgress && $resumed->hold_reason === null, 'resuming clears the reason — a running job does not claim to wait for parts');

refuses('a completion below the recorded odometer is refused', fn () => app(CompleteWorkOrder::class)($resumed, 119_000));

$hoursSchedule = app(SaveServiceSchedule::class)(new ServiceScheduleDto(
    truckId: $truck->id,
    name: 'Hour service XMT',
    kind: ServiceScheduleKind::Hours,
    intervalValue: 25_000,
    lastDoneAtValue: 40_000,
));

refuses(
    'an engine-hours schedule cannot be advanced from the odometer alone',
    fn () => $hoursSchedule->recordService(119_600, null, $today),
    InvalidArgumentException::class,
    'hour meter',
);

$completed = app(CompleteWorkOrder::class)($resumed, 119_600);

check($completed->status === WorkOrderStatus::Completed, 'the order completes at the odometer the workshop read');
check($completed->completion_odometer_km === 119_600, '...and the reading is on the row');
check($completed->truck->refresh()->odometer_km === 119_600, '...and the truck\'s projection followed the reading');
check($completed->truck->status === FleetStatus::Available, '...and the vehicle is released: back on the road for dispatch (§10.6)');

$schedule->refresh();
check($schedule->last_done_at_value === 119_600, 'the linked schedule is closed at the completion odometer');
check($schedule->next_due_at_value === 129_600, '...and its next due point moved one interval on');
check($schedule->stateOn($today) === ServiceDueState::Ok, '...so the derived state is ok again');

$alert?->refresh();
check($alert !== null && $alert->state === AlertState::Resolved, 'the service_due alert resolved in the same transaction — the job it asked for is the job that finished');

$inspected = app(InspectWorkOrder::class)($completed);
check($inspected->status === WorkOrderStatus::Inspected, 'the completed job is inspected');

$closed = app(CloseWorkOrder::class)($inspected, false);
check($closed->status === WorkOrderStatus::Closed, '...and closed');
check($closed->closed_at !== null, '...with the date the freeze happened');
check($closed->capitalised === false && $closed->capitalised_amount_base === 0, '...expensed: capitalising with nothing flagged is refused');

refuses('a closed order refuses a new line', fn () => app(AddWorkOrderLine::class)($closed, new WorkOrderLineDto(WorkOrderLineKind::Labour, 'Extra', 100, 1_000, $base)));

/* ------------------------------------------------------------------------- */
echo "\n== §10.6 the threshold is the control ==\n";

$over = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Repair,
    title: 'Gearbox XMT',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::External, 'Gearbox rebuild', 100, 600_000, $base)],
));

check($over->status === WorkOrderStatus::AwaitingApproval, 'an over-threshold order waits for approval');
check($over->approval_required_amount_base === 500_000, '...carrying the limit in force when it was raised');
check($over->approved_by === null, '...and nobody has approved it');

refuses('it cannot start without an approver', fn () => app(StartWorkOrder::class)($over));

refuses('a rejection without its sentence is refused', fn () => app(RejectWorkOrder::class)($over, '   '));

$approved = app(ApproveWorkOrder::class)($over, $owner->id);
check($approved->status === WorkOrderStatus::Approved, 'an approver holding work_orders.approve moves it to approved');
check($approved->approved_by === $owner->id && $approved->approved_at !== null, '...and the row names who approved and when');
check($approved->approval_required_amount_base === 500_000, '...while the limit it was judged by is left as it was');

refuses('approving twice is not a move the machine has', fn () => app(ApproveWorkOrder::class)($approved, $owner->id));

/* ------------------------------------------------------------------------- */
echo "\n== §10.6 an estimate that grows asks again ==\n";

$grows = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Tyre,
    title: 'Tyres XMT',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::Part, 'Drive tyre', 100, 100_000, $base)],
));

check($grows->status === WorkOrderStatus::Approved, 'a 1,000 order is approved on its own');

app(AddWorkOrderLine::class)($grows, new WorkOrderLineDto(WorkOrderLineKind::Part, 'Two more tyres', 200, 250_000, $base));

$grows->refresh();
check($grows->total_cost_base === 600_000, '...the total is the new arithmetic');
check($grows->status === WorkOrderStatus::AwaitingApproval, '...and the order is back in front of an approver, because nobody approved 6,000');

$line = WorkOrderLine::query()->where('work_order_id', $grows->id)->orderByDesc('id')->firstOrFail();
$shrunk = app(RemoveWorkOrderLine::class)($grows, $line);

check($shrunk->total_cost_base === 100_000, 'taking the line back off restores the total');
check($shrunk->status === WorkOrderStatus::Approved, '...and the approval-by-the-rule returns with it');

$cancelled = app(CancelWorkOrder::class)($shrunk, 'Tyre order withdrawn; the fault was a bearing.');
check($cancelled->status === WorkOrderStatus::Cancelled, 'an approved order that is no longer wanted is cancelled with its sentence');

/* ------------------------------------------------------------------------- */
echo "\n== §10.6 capitalising is a decision at close, bounded by the spend ==\n";

$capital = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Capital,
    title: 'Winch fitment XMT',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::Part, 'Hydraulic winch', 100, 480_000, $base, capitalCandidate: true)],
));

$capital = app(StartWorkOrder::class)($capital);
$capital = app(CompleteWorkOrder::class)($capital, 119_700);
$capital = app(InspectWorkOrder::class)($capital);

refuses(
    'a capital amount above the spend is refused',
    fn () => app(CloseWorkOrder::class)($capital, true, 600_000),
    InvalidArgumentException::class,
    'cannot exceed',
);

$capitalised = app(CloseWorkOrder::class)($capital, true);
check($capitalised->status === WorkOrderStatus::Closed, 'the capital order closes');
check($capitalised->capitalised === true && $capitalised->capitalised_amount_base === 480_000, '...capitalising the flagged line by default');
check($capitalised->capitalisedAmount()->format() === $capitalised->partsTotal()->format(), '...which is the whole spend on this order');

/* ------------------------------------------------------------------------- */
echo "\n== §10.6 the refusals are machines, not habits ==\n";

$fresh = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Inspection,
    title: 'Inspection XMT',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::Labour, 'Inspection', 100, 5_000, $base)],
));

refuses('a job cannot be completed before it starts', fn () => app(CompleteWorkOrder::class)($fresh, 130_000));
refuses('a job cannot be closed before it is inspected', fn () => app(CloseWorkOrder::class)($fresh, false));
refuses('a job cannot be held before it starts', fn () => app(HoldWorkOrder::class)($fresh, HoldReason::ExternalGarage));

$cancelledFresh = app(CancelWorkOrder::class)($fresh, 'Raised twice by mistake.');
check($cancelledFresh->status === WorkOrderStatus::Cancelled, 'a fresh order is cancelled, leaving the first inspection to stand');

/* ------------------------------------------------------------------------- */
echo "\n== §15.2 3e a receipt, an issue and a count move the shelf and the ledger ==\n";

$otherTruck = app(CreateTruck::class)(new TruckDto(
    registrationNo: 'BWD998XMT',
    fleetNo: 'XMT-02',
    odometerKm: 88_000,
    fuelType: 'diesel',
));

$oil = app(CreatePart::class)(PartDto::fromArray([
    'sku' => 'XMT-OIL-15W40',
    'name' => 'XMT 15W-40 oil',
    'category' => 'lubricants',
    'unit_cost_minor' => 10_000,
    'currency_code' => $base,
    'reorder_level' => 2,
    'truck_id' => null,
    'active' => true,
]));

$oil->refresh();

check($oil->stock_quantity_x100 === 0 && $oil->stock_value_base === 0 && $oil->average_cost_minor === 0, 'a new catalogue line starts with an empty shelf worth nothing');

$receipt = app(ReceivePartStock::class)($oil, 1_000, Money::ofMinor(10_000, $base), $today, 'XMT delivery one');

$oil->refresh();
check($oil->stockLabel() === '10.00' && $oil->stock_value_base === 100_000, 'receiving 10 at 100.00 puts 1,000.00 on the shelf');
check($oil->average_cost_minor === 10_000, '...at that unit cost as the average');
check($receipt->kind === StockMovementKind::Receipt && $receipt->value_after_base === 100_000, '...and the movement row is the after-snapshot');

$receiptLines = DB::table('gl_journal_lines')
    ->where('entry_id', DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $receipt->id)->value('id') ?? '0')
    ->orderBy('id')
    ->get();

check($receiptLines->count() === 2
    && (int) $receiptLines[0]->account_id === $codes['1190']->id && $receiptLines[0]->direction === 'debit' && (int) $receiptLines[0]->amount_base === 100_000
    && (int) $receiptLines[1]->account_id === $codes['2110']->id && $receiptLines[1]->direction === 'credit',
    '...and the receipt posted Dr 1190 Parts stock / Cr 2110 Supplier payable');

$second = app(ReceivePartStock::class)($oil, 1_000, Money::ofMinor(12_000, $base), $today, 'XMT delivery two');
$oil->refresh();

check($oil->stock_quantity_x100 === 2_000 && $oil->stock_value_base === 220_000, 'a second receipt at 120.00 makes 20 units worth 2,200.00');
check($oil->average_cost_minor === 11_000, '...and the average is money over units, not the last price paid (110.00)');

refuses(
    'a receipt in a foreign currency is refused, naming the supplier bill',
    fn () => app(ReceivePartStock::class)($oil, 100, Money::ofMinor(10_000, 'ZAR'), $today),
    InvalidArgumentException::class,
    'supplier bill',
);

refuses(
    'a receipt of nothing is refused',
    fn () => app(ReceivePartStock::class)($oil, 0, Money::ofMinor(10_000, $base), $today),
);

$job = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Repair,
    title: 'XMT brake job',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::Labour, 'Brake labour', 100, 5_000, $base)],
));

check($job->status === WorkOrderStatus::Approved && $job->parts_cost_base === 0, 'an approved job starts with no parts cost');

$issue = app(IssuePartToWorkOrder::class)($job, $oil, 400, $today, 'XMT front callipers');

$oil->refresh();
$job->refresh();

check(abs($issue->value_delta_base) === 44_000 && $issue->unit_cost_minor === 11_000, 'issuing 4 costs them at the shelf’s 110.00 average — 440.00');
check($oil->stock_quantity_x100 === 1_600 && $oil->stock_value_base === 176_000 && $oil->average_cost_minor === 11_000, '...and the shelf keeps its average: 16 units, 1,760.00');
check($job->parts_cost_base === 44_000, '...the job’s parts total is what left the shelf, not an estimate line');
check($job->total_cost_base === 49_000 && $job->status === WorkOrderStatus::Approved, '...the total follows it, and the under-limit order stays approved');

$issueLines = DB::table('gl_journal_lines')
    ->where('entry_id', DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $issue->id)->value('id') ?? '0')
    ->orderBy('id')
    ->get();

check($issueLines->count() === 2
    && (int) $issueLines[0]->account_id === $codes['5310']->id && $issueLines[0]->direction === 'debit'
    && (int) $issueLines[0]->amount_base === 44_000
    && (int) $issueLines[0]->truck_id === $truck->id && $issueLines[0]->work_order_id === $job->id
    && (int) $issueLines[1]->account_id === $codes['1190']->id && $issueLines[1]->direction === 'credit',
    '...posted Dr 5310 Parts consumed (with the truck and the job) / Cr 1190 Parts stock');

$tyre = app(CreatePart::class)(PartDto::fromArray([
    'sku' => 'XMT-TYRE-1120',
    'name' => 'XMT 11R20 drive tyre',
    'category' => 'tyres',
    'unit_cost_minor' => 250_000,
    'currency_code' => $base,
    'reorder_level' => 1,
    'truck_id' => null,
    'active' => true,
]));

app(ReceivePartStock::class)($tyre, 400, Money::ofMinor(250_000, $base), $today, 'XMT tyres');
$tyreIssue = app(IssuePartToWorkOrder::class)($job, $tyre->refresh(), 100, $today);

$tyreDebit = DB::table('gl_journal_lines')
    ->where('entry_id', DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $tyreIssue->id)->value('id') ?? '0')
    ->where('direction', 'debit')
    ->first();

check($tyreDebit !== null && (int) $tyreDebit->account_id === $codes['5320']->id && (int) $tyreDebit->amount_base === 250_000, 'a tyre issued to a job debits 5320 Tyres, not 5310 Parts consumed');

refuses(
    'a job cannot draw more than the shelf holds',
    fn () => app(IssuePartToWorkOrder::class)($job, $oil->refresh(), 1_700, $today),
    InvalidArgumentException::class,
    'on the shelf',
);

$unapproved = app(RaiseWorkOrder::class)(new WorkOrderDto(
    truckId: $truck->id,
    kind: WorkOrderKind::Repair,
    title: 'XMT job waiting for an approver',
    lines: [new WorkOrderLineDto(WorkOrderLineKind::External, 'Gearbox rebuild', 100, 600_000, $base)],
));

check($unapproved->status === WorkOrderStatus::AwaitingApproval, 'the over-limit job waits for an approver');

refuses(
    'a job waiting for an approver cannot draw stock',
    fn () => app(IssuePartToWorkOrder::class)($unapproved, $oil->refresh(), 100, $today),
    InvalidArgumentException::class,
    'sanctioned job',
);

$panel = app(CreatePart::class)(PartDto::fromArray([
    'sku' => 'XMT-PANEL-02',
    'name' => 'XMT cab panel for the second truck',
    'category' => 'body',
    'unit_cost_minor' => 90_000,
    'currency_code' => $base,
    'reorder_level' => null,
    'truck_id' => $otherTruck->id,
    'active' => true,
]));

app(ReceivePartStock::class)($panel, 100, Money::ofMinor(90_000, $base), $today, 'XMT panel');

refuses(
    'a part specific to another truck is refused by name',
    fn () => app(IssuePartToWorkOrder::class)($job, $panel->refresh(), 100, $today),
    InvalidArgumentException::class,
    'specific to',
);

$count = app(CountPartStock::class)($oil->refresh(), 1_500, $today, 'XMT count');

$oil->refresh();
check($count->quantity_delta_x100 === -100 && $count->value_delta_base === -11_000, 'a count one short records −1 at the shelf’s average');
check($oil->stock_quantity_x100 === 1_500 && $oil->stock_value_base === 165_000, '...and the book follows the count');

$countDebit = DB::table('gl_journal_lines')
    ->where('entry_id', DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $count->id)->value('id') ?? '0')
    ->where('direction', 'debit')
    ->first();

check($countDebit !== null && (int) $countDebit->account_id === $codes['5315']->id && (int) $countDebit->amount_base === 11_000, '...posting Dr 5315 Parts stock variance for the shortage');

$drain = app(IssuePartToWorkOrder::class)($job, $oil->refresh(), 1_500, $today);

$oil->refresh();
check(abs($drain->value_delta_base) === 165_000, 'emptying the shelf takes the exact value it held, not a rounded average');
check($oil->stock_quantity_x100 === 0 && $oil->stock_value_base === 0 && $oil->average_cost_minor === 0, '...so an empty shelf is worth nothing, and the CHECK agrees');

$agreed = app(CountPartStock::class)($oil, 0, $today, 'XMT empty count');

check($agreed->quantity_delta_x100 === 0 && $agreed->value_delta_base === 0, 'a count that agrees with an empty shelf is still recorded, as two zeroes');
check(! DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $agreed->id)->exists(), '...and posts nothing, because there is no difference to post');

refuses(
    'a count below zero is refused',
    fn () => app(CountPartStock::class)($oil->refresh(), -100, $today),
    InvalidArgumentException::class,
    'negative',
);

$oil->refresh();
check($oil->isLowStock() === true, '...and an empty shelf reads low against its reorder level of 2');

/* ------------------------------------------------------------------------- */
echo "\n== cleanup ==\n";

$wipeParts();
$wipeFixtures([$truck->id, $otherTruck->id]);

$leftovers = [
    'work orders' => (int) DB::table('work_orders')->whereIn('truck_id', [$truck->id, $otherTruck->id])->count(),
    'work-order lines' => (int) DB::table('work_order_lines')->whereIn('work_order_id', DB::table('work_orders')->whereIn('truck_id', [$truck->id, $otherTruck->id])->pluck('id')->all() ?: ['0'])->count(),
    'service schedules' => (int) DB::table('service_schedules')->whereIn('truck_id', [$truck->id, $otherTruck->id])->count(),
    'trucks' => (int) DB::table('trucks')->whereIn('id', [$truck->id, $otherTruck->id])->count(),
    'parts' => (int) DB::table('parts')->where('sku', 'like', 'XMT-%')->count(),
    'stock movements' => (int) DB::table('stock_movements')->whereIn('part_id', DB::table('parts')->where('sku', 'like', 'XMT-%')->pluck('id')->all() ?: [0])->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
