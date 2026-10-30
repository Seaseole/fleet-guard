<?php

declare(strict_types=1);

/**
 * Phase 3 slices 3d–3e — the workshop register, one work order's machine, and the shelf behind it,
 * driven through the real stack.
 *
 * `verify-maintenance.php` proves the Actions and the schema. This sweep proves the screens that
 * carry them: that a schedule's state is worked out on the page rather than stored, that the seats
 * §9.3 names read the register, the order and the parts shelf, that a seat without
 * `work_orders.create` (or `work_orders.close`, `parts.adjust`, `parts.count`) is offered no control
 * that would refuse it, that the register's and the shelf's dialogs write what they say, that the
 * order page walks the §10.6 machine end to end and takes a part from the shelf into its cost, and
 * that a rendered page still shows exactly one filled action.
 *
 * Fixtures live in the `MNT` namespace on the development database. Everything created here is
 * removed afterwards and then asserted gone — including the stock movements' journal entries, deleted
 * under §6.3's lifted barrier — and the job/trip/work-order counters are re-aligned so
 * `documents:number-integrity` sees only surviving rows.
 *
 * Run: php scripts/render-maintenance.php   (set RENDER_MAINTENANCE_TRACE=1 for stack traces)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\CreatePart;
use App\Domain\Maintenance\Actions\RaiseWorkOrder;
use App\Domain\Maintenance\Actions\ReceivePartStock;
use App\Domain\Maintenance\Actions\SaveServiceSchedule;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\Data\ServiceScheduleDto;
use App\Domain\Maintenance\Data\WorkOrderDto;
use App\Domain\Maintenance\Data\WorkOrderLineDto;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\InspectionResult;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Maintenance\Models\Inspection;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\Models\Tyre;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\ServiceScheduleKind;
use App\Domain\Maintenance\TyreStatus;
use App\Domain\Maintenance\WorkOrderKind;
use App\Domain\Maintenance\WorkOrderLineKind;
use App\Domain\Maintenance\WorkOrderStatus;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class MaintenanceRenderSweep extends TestCase
{
    public int $failures = 0;

    private int $companyId = 1;

    /** @var list<int> */
    private array $truckIds = [];

    /** @var list<int> */
    private array $scheduleIds = [];

    /** @var list<int> */
    private array $employeeIds = [];

    /** @var list<string> */
    private array $workOrderIds = [];

    /** @var list<int> */
    private array $partIds = [];

    /** @var list<int> */
    private array $tyreIds = [];

    /** @var list<string> */
    private array $inspectionIds = [];

    /** @var list<string> */
    private array $breakdownIds = [];

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function sweep(): void
    {
        /** @var User $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail();
        $this->companyId = $owner->company_id;
        CurrentCompany::force($this->companyId);

        // Every Action below gates its own write (`work_orders.create`, `.approve`, `.close`), and a
        // sweep that builds fixtures with nobody signed in would be refused before it could test
        // anything. The per-seat checks still act as their own users through `actingAs()`.
        Auth::login($owner);

        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        $clerk = $this->staffAs($owner, RoleKey::StoresClerk, 'render-mnt-check-clerk@fleetguard.local');
        $manager = $this->staffAs($owner, RoleKey::WorkshopManager, 'render-mnt-check-manager@fleetguard.local');
        $approver = $this->staffAs($owner, RoleKey::OperationsManager, 'render-mnt-check-approver@fleetguard.local');
        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-mnt-check-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-mnt-check-dispatcher@fleetguard.local');
        $reader = $this->staffAs($owner, null, 'render-mnt-check-reader@fleetguard.local');

        CurrentCompany::force($this->companyId);

        $base = CurrentCompany::model()->base_currency_code;

        /* Fixtures through the Actions, the way the screens write them. */

        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 970 MNT',
            fleetNo: 'T-MNT1',
            vin: 'VINMNT000000000000001',
            make: 'Probe',
            model: 'Workshop',
            odometerKm: 100_000,
            mainTankLitres: 400,
            auxTankLitres: 200,
        ));
        $this->truckIds[] = $truck->id;

        $mechanic = (app(CreateEmployee::class))(new EmployeeDto(
            employeeNo: 'EMP-MNT001',
            name: 'MNT Verify Mechanic',
            hiredOn: '2023-05-01',
        ));
        $this->employeeIds[] = $mechanic->id;

        // Due 500 km out on a 10,000 km interval: the page must work out "due soon" itself.
        $schedule = (app(SaveServiceSchedule::class))(new ServiceScheduleDto(
            truckId: $truck->id,
            name: 'MNT Engine service',
            kind: ServiceScheduleKind::Km,
            intervalValue: 10_000,
            lastDoneAtValue: 90_500,
        ));
        $this->scheduleIds[] = $schedule->id;

        // Under the BWP 5,000 limit: the rule approves it, so the page must offer "Start the job".
        $under = (app(RaiseWorkOrder::class))(new WorkOrderDto(
            truckId: $truck->id,
            kind: WorkOrderKind::Service,
            title: 'MNT Engine service',
            description: 'MNT render job',
            estimatedHours: 2.0,
            serviceScheduleId: $schedule->id,
            mechanicEmployeeId: $mechanic->id,
            lines: [
                new WorkOrderLineDto(WorkOrderLineKind::Labour, 'MNT labour — filter kit', 200, 25_000, $base),
                new WorkOrderLineDto(WorkOrderLineKind::Part, 'MNT oil filter', 100, 30_000, $base),
            ],
        ), 1);
        $this->workOrderIds[] = $under->id;

        // Above it: awaiting approval, so the page must offer the approver the decision.
        $over = (app(RaiseWorkOrder::class))(new WorkOrderDto(
            truckId: $truck->id,
            kind: WorkOrderKind::Repair,
            title: 'MNT Turbo repair',
            estimatedHours: 4.0,
            lines: [
                new WorkOrderLineDto(WorkOrderLineKind::Labour, 'MNT turbo overhaul', 400, 200_000, $base),
            ],
        ), 1);
        $this->workOrderIds[] = $over->id;

        // The shelf this sweep draws from: a catalogue line and the receipt that put oil on it.
        $part = (app(CreatePart::class))(PartDto::fromArray([
            'sku' => 'MNT-OIL-15W40',
            'name' => 'MNT 15W-40 oil',
            'category' => 'lubricants',
            'unit_cost_minor' => 10_000,
            'currency_code' => $base,
            'reorder_level' => 2,
            'truck_id' => null,
            'active' => true,
        ]));
        $this->partIds[] = $part->id;

        (app(ReceivePartStock::class))($part, 1_000, Money::ofMinor(10_000, $base), Carbon::now(CurrentCompany::timezone()), 'MNT opening stock');

        echo "== The register renders, and a schedule's state is worked out on the page (§10.6, §10.8) ==\n";

        $this->attempt('the owner opens /maintenance and sees the truck, the schedule and both orders', function () use ($owner, $under, $over): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/maintenance')->assertOk()->getContent();

            foreach (['MNT Engine service', 'B 970 MNT', 'MNT Turbo repair', $under->wo_no, $over->wo_no, 'Due soon', '500 km'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the register HTML is missing {$needle}");
                }
            }
        });

        $this->attempt('that page shows no more than one filled action (§5)', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/maintenance')->assertOk()->getContent();
            $this->assertOneFilledAction($html, '/maintenance');
        });

        $this->attempt('the dispatcher reads the register and is offered no recording control', function () use ($dispatcher): void {
            $html = (string) $this->actingAs($dispatcher, 'web')->get('/maintenance')->assertOk()->getContent();

            if (! str_contains($html, 'MNT Engine service')) {
                throw new Exception('the dispatcher cannot see the register (§9.3 gives maintenance.view)');
            }

            foreach (['openRaise', 'openScheduleDialog', 'saveSchedule', 'raiseWorkOrder', 'raiseForSchedule'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the register offered a workshop control ({$control}) to a seat without work_orders.create");
                }
            }
        });

        $this->attempt('the accountant reads the register but is offered no raise, schedule or decision', function () use ($accountant): void {
            $html = (string) $this->actingAs($accountant, 'web')->get('/maintenance')->assertOk()->getContent();

            if (! str_contains($html, 'MNT Engine service')) {
                throw new Exception('the accountant cannot see the register (§9.3 gives maintenance.view)');
            }

            foreach (['openRaise', 'saveSchedule', 'raiseWorkOrder'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the register offered the accountant a control it would refuse ({$control})");
                }
            }
        });

        $this->attempt('a seat with no maintenance permission is refused both routes', function () use ($reader, $under): void {
            foreach (['/maintenance', '/maintenance/work-orders/'.$under->id] as $route) {
                $status = $this->actingAs($reader, 'web')->get($route)->getStatusCode();

                if ($status !== 403) {
                    throw new Exception("{$route} answered {$status}, expected 403");
                }
            }
        });

        echo "\n== The order page carries the machine (§10.6) ==\n";

        $this->attempt('the owner opens the approved order and is offered the next move', function () use ($owner, $under): void {
            $html = (string) $this->actingAs($owner, 'web')
                ->get('/maintenance/work-orders/'.$under->id)
                ->assertOk()
                ->getContent();

            foreach (['MNT labour — filter kit', 'MNT oil filter', 'The job', 'Estimate lines', 'Start the job', 'Plan into the diary', 'Withdraw'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the order page is missing {$needle}");
                }
            }
        });

        $this->attempt('the approved order shows exactly one filled action (§5)', function () use ($owner, $under): void {
            $html = (string) $this->actingAs($owner, 'web')
                ->get('/maintenance/work-orders/'.$under->id)
                ->assertOk()
                ->getContent();

            $this->assertOneFilledAction($html, 'the approved order');
        });

        $this->attempt('the over-limit order offers the approver the decision, and nothing is started before it', function () use ($owner, $over): void {
            $html = (string) $this->actingAs($owner, 'web')
                ->get('/maintenance/work-orders/'.$over->id)
                ->assertOk()
                ->getContent();

            foreach (['Approve the spend', 'Reject', 'Waiting on an approver'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the awaiting-approval page is missing {$needle}");
                }
            }

            if (str_contains($html, 'Start the job')) {
                throw new Exception('an order waiting for approval was offered the start move');
            }

            $this->assertOneFilledAction($html, 'the awaiting-approval order');
        });

        $this->attempt('the dispatcher reads the order and is offered no workshop move', function () use ($dispatcher, $under): void {
            $html = (string) $this->actingAs($dispatcher, 'web')
                ->get('/maintenance/work-orders/'.$under->id)
                ->assertOk()
                ->getContent();

            if (! str_contains($html, 'MNT labour — filter kit')) {
                throw new Exception('the dispatcher cannot read the order (§9.3 gives maintenance.view)');
            }

            foreach (['wire:click="start"', 'open-wo-plan', 'open-wo-line', 'open-wo-complete', 'wire:click="approve"'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the order page offered the dispatcher a move it does not hold ({$control})");
                }
            }
        });

        $this->attempt('the accountant reads the costs and is offered neither the approval nor the close', function () use ($accountant, $over): void {
            $html = (string) $this->actingAs($accountant, 'web')
                ->get('/maintenance/work-orders/'.$over->id)
                ->assertOk()
                ->getContent();

            if (! str_contains($html, 'MNT turbo overhaul') || ! str_contains($html, 'Estimate lines')) {
                throw new Exception('the accountant cannot read the order’s costs');
            }

            foreach (['wire:click="approve"', 'open-wo-close', 'wire:click="close"'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the order page offered the accountant a decision it does not hold ({$control})");
                }
            }
        });

        echo "\n== The shelf renders, and the seats hold the stock gates (§4.3, §9.3) ==\n";

        $this->attempt('the owner opens /parts and sees the catalogue line with what is on the shelf', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/parts')->assertOk()->getContent();

            foreach (['MNT 15W-40 oil', 'MNT-OIL-15W40', '10.00', 'Stock value', 'openReceive', 'openCount'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the parts page is missing {$needle}");
                }
            }
        });

        $this->attempt('that page shows no more than one filled action (§5)', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/parts')->assertOk()->getContent();
            $this->assertOneFilledAction($html, '/parts');
        });

        $this->attempt('the clerk is offered both stock moves, the manager the count, the approver the receipt', function () use ($clerk, $manager, $approver): void {
            $clerkHtml = (string) $this->actingAs($clerk, 'web')->get('/parts')->assertOk()->getContent();

            foreach (['openReceive', 'openCount'] as $control) {
                if (! str_contains($clerkHtml, $control)) {
                    throw new Exception("the stores clerk is not offered ({$control})");
                }
            }

            $managerHtml = (string) $this->actingAs($manager, 'web')->get('/parts')->assertOk()->getContent();

            if (! str_contains($managerHtml, 'openCount') || str_contains($managerHtml, 'openReceive')) {
                throw new Exception('the workshop manager holds parts.count but not parts.adjust — the receipt must not be offered');
            }

            $approverHtml = (string) $this->actingAs($approver, 'web')->get('/parts')->assertOk()->getContent();

            if (! str_contains($approverHtml, 'openReceive') || str_contains($approverHtml, 'openCount')) {
                throw new Exception('the operations manager holds parts.adjust but not parts.count — the count must not be offered');
            }
        });

        $this->attempt('the accountant reads the shelf and is offered neither move', function () use ($accountant): void {
            $html = (string) $this->actingAs($accountant, 'web')->get('/parts')->assertOk()->getContent();

            if (! str_contains($html, 'MNT 15W-40 oil')) {
                throw new Exception('the accountant cannot read the catalogue (§9.3 gives parts.view)');
            }

            foreach (['openReceive', 'openCount'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the parts page offered the accountant a stock move it would refuse ({$control})");
                }
            }
        });

        $this->attempt('a seat without parts.view is refused the register', function () use ($dispatcher): void {
            $status = $this->actingAs($dispatcher, 'web')->get('/parts')->getStatusCode();

            if ($status !== 403) {
                throw new Exception("/parts answered {$status} to a seat without parts.view, expected 403");
            }
        });

        echo "\n== The dialogs move the shelf, and the ledger follows (§15.2 3e) ==\n";

        $this->attempt('a receipt written through the dialog lands on the shelf and posts', function () use ($clerk, $part): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.parts')
                ->call('openReceive', $part->id)
                ->assertDispatched('open-part-receive')
                ->set('stockQuantity', '5.00')
                ->set('stockUnitCost', '120.00')
                ->set('stockDate', Carbon::now(CurrentCompany::timezone())->toDateString())
                ->call('receive')
                ->assertHasNoErrors('stock')
                ->assertDispatched('close-part-receive');

            $fresh = $part->fresh();

            if ($fresh->stock_quantity_x100 !== 1_500 || $fresh->stock_value_base !== 160_000) {
                throw new Exception('the receipt dialog wrote '.$fresh->stock_quantity_x100.' units worth '.$fresh->stock_value_base);
            }

            $movement = DB::table('stock_movements')
                ->where('part_id', $part->id)
                ->where('kind', 'receipt')
                ->orderByDesc('id')
                ->first();

            if ($movement === null || (int) $movement->value_delta_base !== 60_000) {
                throw new Exception('the receipt dialog left no movement, or the wrong value');
            }

            if (! DB::table('gl_journal_entries')->where('source_type', 'stock_movement')->where('source_id', $movement->id)->exists()) {
                throw new Exception('the receipt dialog left no journal entry');
            }
        });

        $this->attempt('a count written through the dialog records the difference', function () use ($clerk, $part): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.parts')
                ->call('openCount', $part->id)
                ->assertDispatched('open-part-count')
                ->set('stockCounted', '14.00')
                ->set('stockDate', Carbon::now(CurrentCompany::timezone())->toDateString())
                ->call('countStock')
                ->assertHasNoErrors('stock')
                ->assertDispatched('close-part-count');

            $fresh = $part->fresh();

            if ($fresh->stock_quantity_x100 !== 1_400) {
                throw new Exception('the count dialog left '.$fresh->stock_quantity_x100.' units, expected 1400');
            }

            $count = DB::table('stock_movements')->where('part_id', $part->id)->where('kind', 'count')->first();

            if ($count === null || (int) $count->quantity_delta_x100 !== -100) {
                throw new Exception('the count dialog did not record a shortage of one unit');
            }
        });

        $this->attempt('the clerk issues a part to the approved job, and the job’s parts cost moves', function () use ($clerk, $under, $part): void {
            $before = WorkOrder::query()->findOrFail($under->id);
            $partsBefore = $before->parts_cost_base;
            $totalBefore = $before->total_cost_base;

            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $under])
                ->set('issuePartId', (string) $part->id)
                ->set('issueQuantity', '2.00')
                ->set('issueDate', Carbon::now(CurrentCompany::timezone())->toDateString())
                ->call('issuePart')
                ->assertDispatched('close-issue-part');

            $order = WorkOrder::query()->findOrFail($under->id);
            $movement = DB::table('stock_movements')
                ->where('part_id', $part->id)
                ->where('work_order_id', $under->id)
                ->first();

            if ($movement === null) {
                throw new Exception('the issue dialog left no movement for the job');
            }

            if ($order->total_cost_base !== $totalBefore + (int) abs($movement->value_delta_base)) {
                throw new Exception('the issue dialog did not move the job’s total by the issued value');
            }

            if ($order->parts_cost_base !== $partsBefore + (int) abs($movement->value_delta_base)) {
                throw new Exception('the issue dialog did not put the issue into the job’s parts total');
            }

            $html = (string) $this->actingAs($clerk, 'web')
                ->get('/maintenance/work-orders/'.$under->id)
                ->assertOk()
                ->getContent();

            foreach (['Parts used', 'MNT 15W-40 oil', 'open-issue-part'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the order page does not show the issue: missing {$needle}");
                }
            }
        });

        $this->attempt('an issue larger than the shelf is refused beside the dialog, and writes nothing', function () use ($clerk, $under, $part): void {
            $movements = (int) DB::table('stock_movements')->where('part_id', $part->id)->count();

            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $under])
                ->set('issuePartId', (string) $part->id)
                ->set('issueQuantity', '99.00')
                ->set('issueDate', Carbon::now(CurrentCompany::timezone())->toDateString())
                ->call('issuePart')
                ->assertHasErrors('issue');

            if ((int) DB::table('stock_movements')->where('part_id', $part->id)->count() !== $movements) {
                throw new Exception('a refused issue wrote a movement anyway');
            }
        });

        $this->attempt('the dispatcher is offered no issue control on the order', function () use ($dispatcher, $under): void {
            $html = (string) $this->actingAs($dispatcher, 'web')
                ->get('/maintenance/work-orders/'.$under->id)
                ->assertOk()
                ->getContent();

            if (str_contains($html, 'open-issue-part')) {
                throw new Exception('the order page offered the dispatcher the issue dialog without work_orders.create');
            }
        });

        echo "\n== The register's dialogs write what they say (§4.3) ==\n";

        $this->attempt('a schedule saved through the dialog lands with its interval and its next due point', function () use ($clerk, $truck): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.index')
                ->set('scheduleTruckId', (string) $truck->id)
                ->set('scheduleName', 'MNT Gearbox service')
                ->set('scheduleKind', 'km')
                ->set('scheduleInterval', '15000')
                ->set('scheduleLastAt', '99900')
                ->call('saveSchedule')
                ->assertHasNoErrors('schedule')
                ->assertDispatched('close-schedule-form');

            $row = DB::table('service_schedules')->where('name', 'MNT Gearbox service')->first();

            if ($row === null
                || (int) $row->interval_value !== 15_000
                || (int) $row->last_done_at_value !== 99_900
                || (int) $row->next_due_at_value !== 114_900) {
                throw new Exception('the schedule dialog wrote the wrong figures');
            }

            $this->scheduleIds[] = (int) $row->id;
        });

        $this->attempt('a schedule interval that is not a number is refused beside the form', function () use ($clerk, $truck): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.index')
                ->set('scheduleTruckId', (string) $truck->id)
                ->set('scheduleName', 'MNT Bad interval')
                ->set('scheduleKind', 'km')
                ->set('scheduleInterval', 'soon')
                ->call('saveSchedule')
                ->assertHasErrors('schedule');

            if (DB::table('service_schedules')->where('name', 'MNT Bad interval')->exists()) {
                throw new Exception('a refused schedule was written anyway');
            }
        });

        $this->attempt('a work order raised through the dialog is approved by the rule, under the limit', function () use ($clerk, $truck, $mechanic, $schedule): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.index')
                ->set('woTruckId', (string) $truck->id)
                ->set('woKind', 'inspection')
                ->set('woTitle', 'MNT Brake check')
                ->set('woMechanicId', (string) $mechanic->id)
                ->set('woScheduleId', (string) $schedule->id)
                ->set('woLines', [[
                    'kind' => 'labour',
                    'description' => 'MNT brake inspection',
                    'quantity' => '1.50',
                    'unitCost' => '200.00',
                    'capital' => false,
                ]])
                ->call('raiseWorkOrder')
                ->assertHasNoErrors();

            $order = WorkOrder::query()->where('title', 'MNT Brake check')->first();

            if ($order === null || $order->status !== WorkOrderStatus::Approved) {
                throw new Exception('the raise dialog did not leave an approved order');
            }

            if ($order->total_cost_base !== 30_000) {
                throw new Exception('the dialog’s estimate is '.$order->total_cost_base.', expected 30000');
            }

            $this->workOrderIds[] = $order->id;
        });

        $this->attempt('a work order raised over the limit waits for an approver', function () use ($clerk, $truck, $approver): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.index')
                ->set('woTruckId', (string) $truck->id)
                ->set('woKind', 'repair')
                ->set('woTitle', 'MNT Gearbox repair')
                ->set('woLines', [[
                    'kind' => 'external',
                    'description' => 'MNT gearbox rebuild',
                    'quantity' => '1',
                    'unitCost' => '6000.00',
                    'capital' => false,
                ]])
                ->call('raiseWorkOrder')
                ->assertHasNoErrors();

            $order = WorkOrder::query()->where('title', 'MNT Gearbox repair')->first();

            if ($order === null || $order->status !== WorkOrderStatus::AwaitingApproval) {
                throw new Exception('an over-limit order was not left waiting for approval');
            }

            if ($order->total_cost_base !== 600_000) {
                throw new Exception('the over-limit estimate is '.$order->total_cost_base.', expected 600000');
            }

            $this->workOrderIds[] = $order->id;

            $html = (string) $this->actingAs($approver, 'web')
                ->get('/maintenance/work-orders/'.$order->id)
                ->assertOk()
                ->getContent();

            if (! str_contains($html, 'Approve the spend')) {
                throw new Exception('the approver is not offered the decision on the over-limit order');
            }
        });

        $this->attempt('a typed quantity that is not a number is refused beside the form', function () use ($clerk, $truck): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.index')
                ->set('woTruckId', (string) $truck->id)
                ->set('woKind', 'repair')
                ->set('woTitle', 'MNT Typo order')
                ->set('woLines', [[
                    'kind' => 'labour',
                    'description' => 'MNT typo',
                    'quantity' => 'two',
                    'unitCost' => '100.00',
                    'capital' => false,
                ]])
                ->call('raiseWorkOrder')
                ->assertHasErrors('woForm');

            if (WorkOrder::query()->where('title', 'MNT Typo order')->exists()) {
                throw new Exception('a refused work order was written anyway');
            }
        });

        echo "\n== The order page walks the machine (§10.6) ==\n";

        $order = WorkOrder::query()->where('title', 'MNT Brake check')->firstOrFail();

        $this->attempt('a line added through the dialog moves the estimate, and removing it moves it back', function () use ($clerk, $order): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->set('lineKind', 'labour')
                ->set('lineDescription', 'MNT wheel alignment')
                ->set('lineQuantity', '1.00')
                ->set('lineUnitCost', '150.00')
                ->call('addLine')
                ->assertHasNoErrors('line')
                ->assertDispatched('close-wo-line');

            $grown = WorkOrder::query()->findOrFail($order->id);

            if ($grown->total_cost_base !== 45_000) {
                throw new Exception('adding the line left the total at '.$grown->total_cost_base);
            }

            $lineId = (int) DB::table('work_order_lines')
                ->where('work_order_id', $order->id)
                ->where('description', 'MNT wheel alignment')
                ->value('id');

            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $grown])
                ->call('removeLine', $lineId)
                ->assertHasNoErrors('line');

            if (WorkOrder::query()->findOrFail($order->id)->total_cost_base !== 30_000) {
                throw new Exception('removing the line did not restore the estimate');
            }
        });

        $this->attempt('the manager plans the job into the diary', function () use ($manager, $order): void {
            Livewire::actingAs($manager)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->set('plannedFor', Carbon::now(CurrentCompany::timezone())->toDateString())
                ->call('plan')
                ->assertHasNoErrors('action')
                ->assertDispatched('close-wo-plan');

            $planned = WorkOrder::query()->findOrFail($order->id);

            if ($planned->status !== WorkOrderStatus::Planned || $planned->planned_for === null) {
                throw new Exception('the plan move did not land on the row');
            }
        });

        $this->attempt('starting the job takes the truck off the road', function () use ($clerk, $order): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->call('start')
                ->assertHasNoErrors('action');

            if (WorkOrder::query()->findOrFail($order->id)->status !== WorkOrderStatus::InProgress) {
                throw new Exception('the job did not start');
            }

            if (DB::table('trucks')->where('id', $order->truck_id)->value('status') !== FleetStatus::InWorkshop->value) {
                throw new Exception('the truck is still dispatchable while the job is under way');
            }
        });

        $this->attempt('a hold carries its reason, and resuming clears it', function () use ($clerk, $order): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->set('holdReason', HoldReason::AwaitingParts->value)
                ->set('holdNote', 'MNT waiting on the filter kit')
                ->call('hold')
                ->assertHasNoErrors('action')
                ->assertDispatched('close-wo-hold');

            $held = WorkOrder::query()->findOrFail($order->id);

            if ($held->status !== WorkOrderStatus::OnHold || $held->hold_reason !== HoldReason::AwaitingParts) {
                throw new Exception('the hold did not land with its reason');
            }

            Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $held])
                ->call('resume')
                ->assertHasNoErrors('action');

            $resumed = WorkOrder::query()->findOrFail($order->id);

            if ($resumed->status !== WorkOrderStatus::InProgress || $resumed->hold_reason !== null) {
                throw new Exception('resuming did not clear the hold');
            }
        });

        $this->attempt('completing the job records the reading and puts the truck back on the road', function () use ($clerk, $order): void {
            $component = Livewire::actingAs($clerk)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->set('completionOdometer', '100400')
                ->call('complete');

            $this->assertNoActionErrors($component, 'the completion');

            $completed = WorkOrder::query()->findOrFail($order->id);
            $truck = DB::table('trucks')->where('id', $order->truck_id)->first();

            if ($completed->status !== WorkOrderStatus::Completed || $completed->completion_odometer_km !== 100_400) {
                throw new Exception('the completion did not land on the row');
            }

            if ((int) $truck->odometer_km !== 100_400) {
                throw new Exception('the truck’s odometer did not follow the reading');
            }

            if ($truck->status !== FleetStatus::Available->value) {
                throw new Exception('§10.6 says completion releases the vehicle, but the truck is still '.$truck->status);
            }
        });

        $this->attempt('the completion advanced the linked schedule, and the register says so', function () use ($owner): void {
            $schedule = ServiceSchedule::query()->where('name', 'MNT Engine service')->firstOrFail();

            if ($schedule->last_done_at_value !== 100_400 || $schedule->next_due_at_value !== 110_400) {
                throw new Exception('the schedule did not move with the completion');
            }

            $html = (string) $this->actingAs($owner, 'web')->get('/maintenance')->assertOk()->getContent();

            if (! str_contains($html, '100,400 km')) {
                throw new Exception('the register does not print the new last-done reading');
            }
        });

        $this->attempt('the job is inspected, and a seat without work_orders.close cannot close it', function () use ($clerk, $order): void {
            $this->assertNoActionErrors(
                Livewire::actingAs($clerk)
                    ->test('pages::maintenance.work-order', ['workOrder' => $order])
                    ->call('inspect'),
                'the inspection',
            );

            if (WorkOrder::query()->findOrFail($order->id)->status !== WorkOrderStatus::Inspected) {
                throw new Exception('the inspection did not land on the row');
            }

            $html = (string) $this->actingAs($clerk, 'web')
                ->get('/maintenance/work-orders/'.$order->id)
                ->assertOk()
                ->getContent();

            if (str_contains($html, 'open-wo-close')) {
                throw new Exception('the close dialog was rendered for a seat without work_orders.close');
            }

            // Livewire's test broker answers `abort(403)` with a 403 response rather than throwing, so
            // the refusal is read off the response — and the row is asked as well, because a status is
            // only worth asserting if nothing was written behind it.
            $refused = false;

            try {
                Livewire::actingAs($clerk)
                    ->test('pages::maintenance.work-order', ['workOrder' => $order])
                    ->call('close')
                    ->assertForbidden();

                $refused = true;
            } catch (Throwable) {
                $refused = false;
            }

            if (! $refused) {
                throw new Exception('a seat without work_orders.close was not refused the close');
            }

            if (WorkOrder::query()->findOrFail($order->id)->status !== WorkOrderStatus::Inspected) {
                throw new Exception('the refused close still moved the order');
            }
        });

        $this->attempt('the manager closes it, freezing the costs as an expense', function () use ($manager, $order): void {
            $this->assertNoActionErrors(
                Livewire::actingAs($manager)
                    ->test('pages::maintenance.work-order', ['workOrder' => $order])
                    ->set('closeCapitalise', false)
                    ->call('close'),
                'the close',
            );

            $closed = WorkOrder::query()->findOrFail($order->id);

            if ($closed->status !== WorkOrderStatus::Closed || $closed->closed_at === null) {
                throw new Exception('the close did not land on the row');
            }

            if ($closed->capitalised) {
                throw new Exception('an expense close reported itself as capitalised');
            }
        });

        $this->attempt('the trail carries every move the page made', function () use ($owner, $order): void {
            $html = (string) $this->actingAs($owner, 'web')
                ->get('/maintenance/work-orders/'.$order->id)
                ->assertOk()
                ->getContent();

            foreach (['in_progress', 'on_hold', 'completed', 'inspected', 'closed'] as $state) {
                if (! str_contains($html, $state)) {
                    throw new Exception("the trail is missing the {$state} move");
                }
            }
        });

        $this->attempt('the approver rejects the over-limit order with its sentence', function () use ($approver): void {
            $order = WorkOrder::query()->where('title', 'MNT Gearbox repair')->firstOrFail();

            Livewire::actingAs($approver)
                ->test('pages::maintenance.work-order', ['workOrder' => $order])
                ->set('rejectReason', 'MNT re-quote from a second garage')
                ->call('reject')
                ->assertHasNoErrors('action')
                ->assertDispatched('close-wo-reject');

            if (WorkOrder::query()->findOrFail($order->id)->status !== WorkOrderStatus::Rejected) {
                throw new Exception('the rejection did not land on the row');
            }
        });

        echo "\n== The three registers write what they say (§15.2 3f) ==\n";

        $this->attempt('a tyre registered through the dialog lands on the spares rack', function () use ($clerk): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.tyres')
                ->call('openNew')
                ->assertDispatched('open-tyre-new')
                ->set('newSerial', 'MNT-TYR-0001')
                ->set('newBrand', 'Michelin')
                ->set('newSize', '315/80R22.5')
                ->set('newCost', '2400.00')
                ->set('newTread', '1500')
                ->call('create')
                ->assertHasNoErrors('newTyre')
                ->assertDispatched('close-tyre-new');

            $tyre = Tyre::query()->where('serial', 'MNT-TYR-0001')->first();

            if ($tyre === null || $tyre->status !== TyreStatus::OnSpares || $tyre->purchase_cost_base !== 240_000) {
                throw new Exception('the tyre dialog did not leave a casing on the rack at its price');
            }

            $this->tyreIds[] = $tyre->id;
        });

        $tyre = Tyre::query()->where('serial', 'MNT-TYR-0001')->firstOrFail();

        $this->attempt('fitting it through the dialog opens one position and names the slot', function () use ($clerk, $tyre, $truck): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.tyres')
                ->call('openFit', $tyre->id)
                ->assertDispatched('open-tyre-fit')
                ->set('fitTruckId', (string) $truck->id)
                ->set('fitSlot', 'FL')
                ->set('fitAt', Carbon::now(CurrentCompany::timezone())->format('Y-m-d\TH:i'))
                ->set('fitOdometer', '100400')
                ->call('fit')
                ->assertHasNoErrors('fit')
                ->assertDispatched('close-tyre-fit');

            $fresh = $tyre->fresh();

            if ($fresh->status !== TyreStatus::InService || $fresh->openPosition === null || $fresh->openPosition->position->value !== 'FL') {
                throw new Exception('the fit dialog did not open the front-left position');
            }
        });

        $this->attempt('an inspection recorded through the dialog stores its checklist answers', function () use ($manager, $truck): void {
            Livewire::actingAs($manager)
                ->test('pages::maintenance.inspections')
                ->call('openRecord')
                ->assertDispatched('open-inspection-record')
                ->set('inspTruckId', (string) $truck->id)
                ->set('inspDoneAt', Carbon::now(CurrentCompany::timezone())->format('Y-m-d\TH:i'))
                ->call('record')
                ->assertHasNoErrors('inspection')
                ->assertDispatched('close-inspection-record');

            $row = Inspection::query()->where('truck_id', $truck->id)->orderByDesc('id')->first();

            if ($row === null || $row->result !== InspectionResult::Pass || count($row->checklist) !== 8) {
                throw new Exception('the inspection dialog did not leave a pass with every item answered');
            }

            $this->inspectionIds[] = $row->id;
        });

        $this->attempt('a failed inspection through the dialog names its item and its defect line', function () use ($manager, $truck): void {
            Livewire::actingAs($manager)
                ->test('pages::maintenance.inspections')
                ->call('openRecord')
                ->set('inspTruckId', (string) $truck->id)
                ->set('inspDoneAt', Carbon::now(CurrentCompany::timezone())->format('Y-m-d\TH:i'))
                ->set('answers.brakes', false)
                ->set('inspResult', 'fail')
                ->set('defects', ['MNT rear brakes below limit'])
                ->call('record')
                ->assertHasNoErrors('inspection')
                ->assertDispatched('close-inspection-record');

            $row = Inspection::query()->where('truck_id', $truck->id)->orderByDesc('id')->first();

            if ($row === null || $row->result !== InspectionResult::Fail || array_keys($row->failedChecklistItems()) !== ['brakes']) {
                throw new Exception('the inspection dialog did not leave a fail naming the brakes');
            }

            if ($row->defectLines() !== ['MNT rear brakes below limit']) {
                throw new Exception('the inspection dialog lost the defect line');
            }

            $this->inspectionIds[] = $row->id;
        });

        $this->attempt('a breakdown recorded through the dialog is a stop with a place and hours', function () use ($dispatcher, $truck): void {
            Livewire::actingAs($dispatcher)
                ->test('pages::maintenance.breakdowns')
                ->call('openRecord')
                ->assertDispatched('open-breakdown-record')
                ->set('bdTruckId', (string) $truck->id)
                ->set('bdOccurredAt', Carbon::now(CurrentCompany::timezone())->format('Y-m-d\TH:i'))
                ->set('bdLocation', 'MNT A1 north of Palapye')
                ->set('bdDescription', 'MNT turbo failure under load')
                ->set('bdCause', 'mechanical')
                ->set('bdHoursLost', '6')
                ->call('record')
                ->assertHasNoErrors('breakdown')
                ->assertDispatched('close-breakdown-record');

            $row = Breakdown::query()->where('truck_id', $truck->id)->first();

            if ($row === null || $row->hours_lost !== 6 || $row->work_order_id !== null) {
                throw new Exception('the breakdown dialog did not leave an un-repaired stop with its hours');
            }

            $this->breakdownIds[] = $row->id;
        });

        $this->attempt('the truck’s own record shows the tyre, the checks and the stop', function () use ($owner, $truck): void {
            $html = (string) $this->actingAs($owner, 'web')
                ->get('/fleet/trucks/'.$truck->id)
                ->assertOk()
                ->getContent();

            foreach (['Workshop history', 'MNT-TYR-0001', 'Pre-trip walk-around', 'MNT A1 north of Palapye'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the vehicle page is missing {$needle}");
                }
            }
        });

        $this->attempt('removing the tyre through the dialog prices the stint', function () use ($clerk, $tyre): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.tyres')
                ->call('openRemove', $tyre->id)
                ->assertDispatched('open-tyre-remove')
                ->set('removeAt', Carbon::now(CurrentCompany::timezone())->addDay()->format('Y-m-d\TH:i'))
                ->set('removeOdometer', '112000')
                ->set('removeStatus', 'on_spares')
                ->call('remove')
                ->assertHasNoErrors('remove')
                ->assertDispatched('close-tyre-remove');

            $fresh = $tyre->fresh();

            if ($fresh->status !== TyreStatus::OnSpares || $fresh->openPosition !== null) {
                throw new Exception('the removal dialog did not close the fitment and return the casing');
            }

            $closed = $fresh->positions()->whereNotNull('removed_at')->first();

            if ($closed === null || $closed->kilometresRun() !== 11_600
                || $closed->cost_per_km_x10000 !== intdiv(240_000 * 100 + 5_800, 11_600)) {
                throw new Exception('the removal dialog did not write 11,600 km and the rate it cost');
            }
        });

        $this->attempt('the dispatcher is offered no repair control; the clerk raises the job', function () use ($dispatcher, $clerk, $truck): void {
            $breakdown = Breakdown::query()->where('truck_id', $truck->id)->firstOrFail();

            $html = (string) $this->actingAs($dispatcher, 'web')->get('/breakdowns')->assertOk()->getContent();

            if (! str_contains($html, 'openRecord')) {
                throw new Exception('the dispatcher is not offered the breakdown dialog');
            }

            if (str_contains($html, 'openRepair')) {
                throw new Exception('the breakdown register offered the repair to a seat without work_orders.create');
            }

            Livewire::actingAs($clerk)
                ->test('pages::maintenance.breakdowns')
                ->call('openRepair', $breakdown->id)
                ->assertDispatched('open-breakdown-repair')
                ->set('repairHours', '8.00')
                ->set('repairRate', '220.00')
                ->call('raiseRepair')
                ->assertHasNoErrors('repairForm');

            $order = WorkOrder::query()->where('title', 'MNT turbo failure under load')->first();
            $fresh = $breakdown->fresh();

            if ($order === null || $order->kind !== WorkOrderKind::Breakdown || $order->truck_id !== $truck->id) {
                throw new Exception('the repair dialog did not raise a breakdown job on the truck');
            }

            if ($order->total_cost_base !== 176_000 || $order->status !== WorkOrderStatus::Approved) {
                throw new Exception('the repair dialog’s estimate is '.$order->total_cost_base.', expected 176000 under the limit');
            }

            if ($fresh->work_order_id !== $order->id) {
                throw new Exception('the breakdown does not name the job it got');
            }

            $this->workOrderIds[] = $order->id;
        });

        echo "\n== The registers render, and the seats hold their gates (§4.3, §9.3) ==\n";

        $this->attempt('the owner opens all three registers with their rows', function () use ($owner): void {
            $tyres = (string) $this->actingAs($owner, 'web')->get('/tyres')->assertOk()->getContent();

            foreach (['MNT-TYR-0001', 'Michelin', 'New tyre'] as $needle) {
                if (! str_contains($tyres, $needle)) {
                    throw new Exception("the tyre register is missing {$needle}");
                }
            }

            $inspections = (string) $this->actingAs($owner, 'web')->get('/inspections')->assertOk()->getContent();

            foreach (['MNT rear brakes below limit', 'Pre-trip walk-around', 'Record an inspection'] as $needle) {
                if (! str_contains($inspections, $needle)) {
                    throw new Exception("the inspection register is missing {$needle}");
                }
            }

            $breakdowns = (string) $this->actingAs($owner, 'web')->get('/breakdowns')->assertOk()->getContent();

            foreach (['MNT A1 north of Palapye', 'Mechanical failure', 'Record a breakdown'] as $needle) {
                if (! str_contains($breakdowns, $needle)) {
                    throw new Exception("the breakdown register is missing {$needle}");
                }
            }

            if (! str_contains($breakdowns, 'WO-')) {
                throw new Exception('the breakdown register does not link the repair it got');
            }
        });

        $this->attempt('each register shows no more than one filled action (§5)', function () use ($owner): void {
            foreach (['/tyres', '/inspections', '/breakdowns'] as $route) {
                $html = (string) $this->actingAs($owner, 'web')->get($route)->assertOk()->getContent();
                $this->assertOneFilledAction($html, $route);
            }
        });

        $this->attempt('the clerk writes tyres but not inspections or breakdowns', function () use ($clerk): void {
            $tyres = (string) $this->actingAs($clerk, 'web')->get('/tyres')->assertOk()->getContent();

            if (! str_contains($tyres, 'openNew')) {
                throw new Exception('the stores clerk is not offered the new-tyre dialog');
            }

            foreach (['/inspections', '/breakdowns'] as $route) {
                $html = (string) $this->actingAs($clerk, 'web')->get($route)->assertOk()->getContent();

                if (str_contains($html, 'openRecord')) {
                    throw new Exception("{$route} offered the stores clerk a record dialog it would refuse");
                }
            }
        });

        $this->attempt('the accountant reads all three and is offered nothing that writes', function () use ($accountant): void {
            foreach (['/tyres', '/inspections', '/breakdowns'] as $route) {
                $html = (string) $this->actingAs($accountant, 'web')->get($route)->assertOk()->getContent();

                foreach (['openNew', 'openFit', 'openRemove', 'openRecord', 'openRepair'] as $control) {
                    if (str_contains($html, $control)) {
                        throw new Exception("{$route} offered the accountant a write control ({$control})");
                    }
                }
            }
        });

        $this->attempt('a seat with no maintenance permission is refused all three registers', function () use ($reader): void {
            foreach (['/tyres', '/inspections', '/breakdowns'] as $route) {
                $status = $this->actingAs($reader, 'web')->get($route)->getStatusCode();

                if ($status !== 403) {
                    throw new Exception("{$route} answered {$status}, expected 403");
                }
            }
        });
    }

    private function staffAs(User $owner, ?RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render MNT Check '.($role?->label() ?? 'No Role');
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);

        if ($role !== null) {
            app(ChangeUserRole::class)($user, $role);
        }

        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
    }

    /**
     * Counts the filled actions a user actually sees, in a real render of one real state
     * (`bg-accent text-on-accent` is the primary variant's signature; dialogs are stripped because a
     * server render never opens one).
     */
    private function assertOneFilledAction(string $html, string $label): void
    {
        $visible = preg_replace('#<dialog\b[^>]*>.*?</dialog>#s', '', $html) ?? $html;

        preg_match_all('/<(?:button|a)\b[^>]*class="[^"]*bg-accent text-on-accent[^"]*"[^>]*>(.{0,120})/s', $visible, $found);

        $count = count($found[0] ?? []);

        if ($count > 1) {
            $labels = array_map(
                static fn (string $tail): string => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($tail)) ?? ''), 0, 30) ?: '(icon)',
                $found[1] ?? [],
            );

            throw new Exception(sprintf(
                '%s: %d filled actions on screen together (%s) — §5 allows one per view',
                $label,
                $count,
                implode(' | ', $labels),
            ));
        }
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;

            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";

            if (getenv('RENDER_MAINTENANCE_TRACE') !== false) {
                echo $exception->getTraceAsString()."\n";
            }
        }
    }

    /**
     * The page's own refusals land in the `action` bag as a sentence. PHPUnit's `assertHasNoErrors`
     * cannot report them from this harness (its exporter needs PHPUnit's own configuration), so the
     * sweep reads the bag itself.
     */
    private function assertNoActionErrors(Testable $component, string $what): void
    {
        $messages = $component->errors()->get('action');

        if ($messages !== []) {
            throw new Exception("{$what} was refused: ".implode(' | ', $messages));
        }
    }

    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 240);
    }

    public function drive(): void
    {
        $this->setUp();

        $this->companyId = (int) DB::table('users')->where('email', config('fleet.identity.owner_email'))->value('company_id');

        $this->purge();
        $this->alignCounters('before the run, after an earlier run\'s debris');

        try {
            $this->sweep();
        } finally {
            $this->purge();
            $this->assertNothingSurvives();

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    /** Delete this namespace's rows, children before parents. */
    private function purge(): void
    {
        CurrentCompany::forget();

        $truckIds = array_values(array_unique(array_merge(
            $this->truckIds,
            DB::table('trucks')->where('registration_no', 'like', '%MNT')->pluck('id')->all(),
        )));

        $workOrderIds = array_values(array_unique(array_merge(
            $this->workOrderIds,
            DB::table('work_orders')->where('title', 'like', 'MNT %')->pluck('id')->all(),
        )));

        $scheduleIds = array_values(array_unique(array_merge(
            $this->scheduleIds,
            $truckIds === [] ? [] : DB::table('service_schedules')->whereIn('truck_id', $truckIds)->pluck('id')->all(),
        )));

        $employeeIds = array_values(array_unique(array_merge(
            $this->employeeIds,
            DB::table('employees')->where('employee_no', 'like', 'EMP-MNT%')->pluck('id')->all(),
        )));

        $alertIds = $scheduleIds === []
            ? []
            : DB::table('alerts')
                ->where('subject_type', 'service_schedule')
                ->whereIn('subject_id', array_map('strval', $scheduleIds))
                ->pluck('id')
                ->all();

        $partIds = array_values(array_unique(array_merge(
            $this->partIds,
            DB::table('parts')->where('sku', 'like', 'MNT-%')->pluck('id')->all(),
        )));

        $tyreIds = array_values(array_unique(array_merge(
            $this->tyreIds,
            DB::table('tyres')->where('serial', 'like', 'MNT-%')->pluck('id')->all(),
        )));

        $inspectionIds = array_values(array_unique(array_merge(
            $this->inspectionIds,
            $truckIds === [] ? [] : DB::table('inspections')->whereIn('truck_id', $truckIds)->pluck('id')->all(),
        )));

        $breakdownIds = array_values(array_unique(array_merge(
            $this->breakdownIds,
            $truckIds === [] ? [] : DB::table('breakdowns')->whereIn('truck_id', $truckIds)->pluck('id')->all(),
        )));

        $movementIds = DB::table('stock_movements')
            ->where(fn ($query) => $query
                ->whereIn('part_id', $partIds ?: [0])
                ->orWhereIn('work_order_id', $workOrderIds ?: ['0']))
            ->pluck('id')
            ->all();

        $entryIds = $movementIds === []
            ? []
            : DB::table('gl_journal_entries')
                ->where('source_type', 'stock_movement')
                ->whereIn('source_id', $movementIds)
                ->pluck('id')
                ->all();

        if ($entryIds !== []) {
            LedgerBarrier::withLiftedGuard('render-maintenance cleanup: the stock movements this run posted', null, function () use ($entryIds): void {
                $this->deleteIn('gl_journal_lines', 'entry_id', $entryIds);
                $this->deleteIn('gl_journal_entries', 'id', $entryIds);
            });
        }

        $this->deleteIn('stock_movements', 'id', $movementIds);
        $this->deleteIn('audits', 'auditable_id', array_map('strval', $partIds));
        $this->deleteIn('parts', 'id', $partIds);
        $this->deleteIn('alerts', 'id', $alertIds);
        $this->deleteIn('operational_events', 'aggregate_id', array_map('strval', $alertIds));
        $this->deleteIn('operational_events', 'aggregate_id', array_map('strval', $scheduleIds));
        $this->deleteIn('operational_events', 'aggregate_id', array_map('strval', $workOrderIds));
        // Inspections and breakdowns cite a truck and a work order; positions cite a tyre and a truck.
        $this->deleteIn('inspections', 'id', array_map('strval', $inspectionIds));
        $this->deleteIn('breakdowns', 'id', array_map('strval', $breakdownIds));
        $this->deleteIn('tyre_positions', 'tyre_id', $tyreIds);
        $this->deleteIn('tyre_positions', 'truck_id', $truckIds);
        $this->deleteIn('tyres', 'id', $tyreIds);
        $this->deleteIn('audits', 'auditable_id', array_map('strval', array_merge($tyreIds, $inspectionIds, $breakdownIds)));
        $this->deleteIn('work_order_lines', 'work_order_id', $workOrderIds);
        $this->deleteIn('work_orders', 'id', $workOrderIds);
        $this->deleteIn('service_schedules', 'id', $scheduleIds);
        $this->deleteIn('odometer_readings', 'truck_id', $truckIds);
        $this->deleteIn('trucks', 'id', $truckIds);
        $this->deleteIn('employees', 'id', $employeeIds);

        $probeIds = DB::table('users')->where('email', 'like', 'render-mnt-check-%@fleetguard.local')->pluck('id')->all();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
        $this->deleteIn('operational_events', 'actor_id', $probeIds);
        if ($probeIds !== []) {
            DB::table('number_sequences')->whereIn('last_used_by', $probeIds)->update(['last_used_by' => null]);
        }
        DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
        DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();
    }

    /**
     * Delete by id list, and only when there is a list. ULID columns are compared to the string `'0'`
     * rather than the integer, because MySQL truncates a char column compared to a number.
     *
     * @param  list<string|int>  $ids
     */
    private function deleteIn(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table($table)->whereIn($column, $ids)->delete();
    }

    private function alignCounters(string $when): void
    {
        CurrentCompany::force($this->companyId);

        foreach ([
            'job' => ['freight_jobs', 'job_no'],
            'trip' => ['trips', 'trip_no'],
            'work_order' => ['work_orders', 'wo_no'],
        ] as $scope => [$table, $column]) {
            $highest = DB::table($table)
                ->where('company_id', $this->companyId)
                ->pluck($column)
                ->map(static function (string $number): int {
                    $dash = strpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                })
                ->max() ?? 0;

            DB::table('number_sequences')
                ->where('company_id', $this->companyId)
                ->where('scope', $scope)
                ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        }

        CurrentCompany::forget();

        echo "  counters aligned to the surviving rows ({$when})\n";
    }

    private function assertNothingSurvives(): void
    {
        $leftovers = [
            'work orders' => (int) DB::table('work_orders')->where('title', 'like', 'MNT %')->count(),
            'work-order lines' => (int) DB::table('work_order_lines')
                ->whereIn('work_order_id', DB::table('work_orders')->where('title', 'like', 'MNT %')->pluck('id') ?: ['0'])
                ->count(),
            'service schedules' => (int) DB::table('service_schedules')
                ->whereIn('id', $this->scheduleIds ?: [0])
                ->count(),
            'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%MNT')->count(),
            'parts' => (int) DB::table('parts')->where('sku', 'like', 'MNT-%')->count(),
            'stock movements' => (int) DB::table('stock_movements')
                ->whereIn('part_id', DB::table('parts')->where('sku', 'like', 'MNT-%')->pluck('id')->all() ?: [0])
                ->count(),
            'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-MNT%')->count(),
            'tyres' => (int) DB::table('tyres')->where('serial', 'like', 'MNT-%')->count(),
            'tyre positions' => (int) DB::table('tyre_positions')
                ->whereIn('tyre_id', DB::table('tyres')->where('serial', 'like', 'MNT-%')->pluck('id')->all() ?: [0])
                ->orWhereIn('truck_id', DB::table('trucks')->where('registration_no', 'like', '%MNT')->pluck('id')->all() ?: [0])
                ->count(),
            'inspections' => (int) DB::table('inspections')
                ->whereIn('truck_id', DB::table('trucks')->where('registration_no', 'like', '%MNT')->pluck('id')->all() ?: [0])
                ->count(),
            'breakdowns' => (int) DB::table('breakdowns')
                ->whereIn('truck_id', DB::table('trucks')->where('registration_no', 'like', '%MNT')->pluck('id')->all() ?: [0])
                ->count(),
            'probe seats' => (int) DB::table('users')->where('email', 'like', 'render-mnt-check-%@fleetguard.local')->count(),
        ];

        foreach ($leftovers as $what => $count) {
            echo $count === 0 ? "  ok    no {$what} survive\n" : "  FAIL  {$count} {$what} survive\n";

            if ($count !== 0) {
                $this->failures++;
            }
        }

        // The run drew job, trip and work-order numbers; with its rows gone, a counter still pointing
        // past the last surviving number reads as drift. Align first, then ask the command.
        $this->alignCounters('after cleanup, so the counters match the rows that survived');

        $exit = Artisan::call('documents:number-integrity');

        if ($exit !== 0) {
            $this->failures++;
            echo '  FAIL  number integrity — '.mb_substr(str_replace("\n", ' ', Artisan::output()), 0, 200)."\n";
        } else {
            echo "  ok    the counters and the surviving rows agree\n";
        }

        echo '  ok    the demo fleet is intact: '.DB::table('trucks')->count()." trucks registered\n";
    }
}

$sweep = new MaintenanceRenderSweep('sweep');

try {
    $sweep->drive();
} catch (Throwable $crashed) {
    echo "\n  FAIL  the sweep stopped — ".$crashed::class.': '.$crashed->getMessage()."\n";
    echo $crashed->getTraceAsString()."\n";

    $sweep->failures++;
}

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
