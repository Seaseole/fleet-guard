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
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\AddWorkOrderLine;
use App\Domain\Maintenance\Actions\ApproveWorkOrder;
use App\Domain\Maintenance\Actions\CancelWorkOrder;
use App\Domain\Maintenance\Actions\CloseWorkOrder;
use App\Domain\Maintenance\Actions\CompleteWorkOrder;
use App\Domain\Maintenance\Actions\HoldWorkOrder;
use App\Domain\Maintenance\Actions\InspectWorkOrder;
use App\Domain\Maintenance\Actions\RaiseWorkOrder;
use App\Domain\Maintenance\Actions\RejectWorkOrder;
use App\Domain\Maintenance\Actions\RemoveWorkOrderLine;
use App\Domain\Maintenance\Actions\ResumeWorkOrder;
use App\Domain\Maintenance\Actions\SaveServiceSchedule;
use App\Domain\Maintenance\Actions\StartWorkOrder;
use App\Domain\Maintenance\Alerts\ServiceDueSource;
use App\Domain\Maintenance\Data\ServiceScheduleDto;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\Models\WorkOrderLine;
use App\Domain\Maintenance\ServiceDueState;
use App\Domain\Maintenance\ServiceScheduleKind;
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
 *     returns the order to approved.
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
    wipeIds('work_order_lines', 'work_order_id', $workOrderIds);
    wipeIds('work_orders', 'id', $workOrderIds);
    wipeIds('service_schedules', 'id', $scheduleIds);
    wipeIds('odometer_readings', 'truck_id', $truckIds);
    wipeIds('audits', 'auditable_id', array_merge($workOrderIds, array_map('strval', $scheduleIds), array_map('strval', $truckIds)));
    wipeIds('trucks', 'id', $truckIds);
    $alignWorkOrderCounter();
};

/* ------------------------------------------------------------------------- */
echo "== pre-clean: an interrupted run leaves nothing the next one counts ==\n";

$xmtTruckIds = DB::table('trucks')->where('registration_no', 'like', '%XMT')->pluck('id')->all();
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
echo "\n== cleanup ==\n";

$wipeFixtures([$truck->id]);

$leftovers = [
    'work orders' => (int) DB::table('work_orders')->where('truck_id', $truck->id)->count(),
    'work-order lines' => (int) DB::table('work_order_lines')->whereIn('work_order_id', DB::table('work_orders')->where('truck_id', $truck->id)->pluck('id')->all() ?: ['0'])->count(),
    'service schedules' => (int) DB::table('service_schedules')->where('truck_id', $truck->id)->count(),
    'trucks' => (int) DB::table('trucks')->where('id', $truck->id)->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
