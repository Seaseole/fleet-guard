<?php

declare(strict_types=1);

/**
 * Phase 2 slice 2b — the dispatch board, driven through the real HTTP/Livewire stack.
 *
 * `verify-operations.php` proves the gate. It cannot prove the two things this screen exists for: that
 * the findings the gate computes are **printed on the cell the dispatcher is looking at**, and that the
 * click which commits says so when it refuses. A gate that is correct in a script and invisible on the
 * board is a gate the yard discovers at the border.
 *
 * The other half of what this file watches is what the screen must *not* show. Hides margins from
 * the seat that reads a dispatch board most often, so a quoted amount must never reach this HTML —
 * the one assertion here that a passing domain test would sail straight past.
 *
 * Slice 2f's border half rides here too: a gate registered through its dialog, a crossing planned,
 * entered, exited, charged and finished through the trip's own section, and the same section read by a
 * dispatcher who holds `crossborder.view` and none of the controls.
 *
 * Not a test suite: `tests/` stays out of the repository. Fixtures live in the `OPR` namespace
 * on the development database; everything created is removed and then asserted removed, including the
 * two operational counters, which `documents:number-integrity` compares against the surviving rows.
 *
 * Run: php scripts/render-operations.php   (set RENDER_OPS_TRACE=1 for stack traces)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\RetentionClass;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\BorderCost;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\BorderPost;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Load;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class OperationsRenderSweep extends TestCase
{
    public int $failures = 0;

    private ?int $ownerCompany = null;

    private ?CarbonImmutable $runStartedAt = null;

    /** @var list<int> ids of trucks this run created or adopted */
    private array $truckIds = [];

    /** @var list<string> ULIDs of jobs, including the foreign tenant's probe row */
    private array $jobIds = [];

    /** @var list<string> */
    private array $tripIds = [];

    /** @var list<int> */
    private array $employeeIds = [];

    /** The trip left on hold by the hold test, so the release test can act on the same row. */
    private string $heldTripId = '';

    /** The trip the board dispatched, so the hold tests act on it instead of booking the truck twice. */
    private string $assignedTripId = '';

    /** The trip the simulator tracked, so the map assertions read the same journey. */
    private string $gpsTripId = '';

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
        $this->ownerCompany = $owner->company_id;
        CurrentCompany::force($owner->company_id);

        // The sweep measures the board's authorisation and its markup; the MFA redirect belongs to
        // render-identity, and a 302 here would be reported as a broken dispatch screen.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-ops-check-dispatcher@fleetguard.local');
        $workshop = $this->staffAs($owner, RoleKey::WorkshopManager, 'render-ops-check-workshop@fleetguard.local');
        $reader = $this->staffAs($owner, null, 'render-ops-check-reader@fleetguard.local');
        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-ops-check-accountant@fleetguard.local');

        // `staffAs()` ends by forgetting the company context, because the role swap has to be the one
        // company the acting user belongs to; without re-establishing it here the next scoped read trips
        // the tenancy guard rather than the board.
        CurrentCompany::force($owner->company_id);

        $monday = CarbonImmutable::now(CurrentCompany::timezone())->addDays(21)->startOfWeek();
        $week = $monday->toDateString();

        /*
         * Fixtures through the Actions, the way the board writes them: a priced, accepted job (only
         * `accepted`/`in_execution` may run a trip), an available truck, a driver whose papers cover
         * the week, and a second driver whose licence lapsed last week.
         */
        $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'OPR Board Customer', code: 'OPR-CUST', paymentTerms: 'net_30'));
        $route = (app(CreateRoute::class))(new RouteDto(
            code: 'OPR-GBE-RSW',
            origin: 'Gaborone',
            destination: 'Robotshill',
            originCountryCode: 'BW',
            destinationCountryCode: 'BW',
            distanceKm: '240',
        ));

        $job = (app(CreateJob::class))(new JobDto(
            customerId: $customer->id,
            routeId: $route->id,
            commodity: 'board fixture pallets',
            tonnes: 12.0,
            requiredLoadOn: $monday->toDateString(),
            requiredDeliveryOn: $monday->addDays(4)->toDateString(),
            notes: 'OPR fixture job',
        ), 1);
        $this->jobIds[] = $job->id;

        // A quote with a number easy to grep for: if any part of this board can see the price, it fails loudly.
        $job = (app(QuoteJob::class))($job, new JobQuoteDto(
            rateType: RouteRateType::PerTrip,
            amountMajor: '777777',
            currencyCode: CurrentCompany::model()->base_currency_code,
            requiredLoadOn: $monday->toDateString(),
            requiredDeliveryOn: $monday->addDays(4)->toDateString(),
        ), 1);
        $job = (app(AcceptJob::class))($job, 1);

        $enquiry = (app(CreateJob::class))(new JobDto(customerId: $customer->id, routeId: $route->id, notes: 'OPR fixture enquiry'), 1);
        $this->jobIds[] = $enquiry->id;

        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 941 OPR',
            fleetNo: 'T-OPR1',
            vin: 'VINOPR000000000000001',
            make: 'Probe',
            model: 'Board',
            odometerKm: 150_000,
            capacityTonnes: 32.0,
            purchaseAmountBase: 100_000_00,
        ));
        $this->truckIds[] = $truck->id;

        $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 941 OPR', type: 'flatbed', fleetNo: 'TR-OPR1', capacityTonnes: 30.0));

        $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-OPR001', name: 'OPR Board Driver', hiredOn: '2024-03-01', nationalId: 'ID-OPR000001'));
        $this->employeeIds[] = $employee->id;
        $driver = (app(CreateDriver::class))(new DriverDto(
            employeeId: $employee->id,
            licenceNo: 'DL-OPR0001',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
            allowanceRateBase: 30_000,
            kioskEnabled: true,
        ));

        $lapsedEmployee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-OPR002', name: 'OPR Lapsed Driver', hiredOn: '2023-01-01'));
        $this->employeeIds[] = $lapsedEmployee->id;
        $lapsed = (app(CreateDriver::class))(new DriverDto(
            employeeId: $lapsedEmployee->id,
            licenceNo: 'DL-OPR0002',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->subDays(4)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(3)->toDateString(),
            allowanceRateBase: 30_000,
        ));

        echo "== The board renders for the seats the design says may read it ==\n";

        $this->attempt('the owner opens /dispatch and the week is a grid of seven days', function () use ($owner, $monday, $week): void {
            $html = (string) $this->actingAs($owner, 'web')->get("/dispatch?week={$week}")->assertOk()->getContent();

            foreach (['B 941 OPR', $monday->format('D'), $monday->addDays(6)->format('D')] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the board HTML is missing {$needle}");
                }
            }

            $columns = preg_match_all('/<th[^>]*scope="col"/', $html);

            // One sticky truck column plus the seven days: the grid, not a list.
            if ($columns !== 8) {
                throw new Exception("expected 8 column headers, got {$columns}");
            }
        });

        $this->pageContains($dispatcher, '/dispatch?week='.$week, 'B 941 OPR', 'a dispatcher may open the board (this is their screen)');

        $this->attempt('the week lives in the URL, so a board survives a refresh and a shared link', function () use ($owner, $monday): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->addDays(7)->toDateString()])
                ->call('shiftWeek', -7)
                ->assertSet('start', $monday->toDateString());
        });

        echo "\n== the board shows the commitment, never the price ==\n";

        $this->attempt('the accepted job is offered; its quoted amount never reaches the HTML', function () use ($owner, $job, $truck, $monday): void {
            $board = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->html();

            if (! str_contains($board, $job->job_no)) {
                throw new Exception('the accepted job is not offered to a free cell at all');
            }

            // The plan dialog is markup on the same page, so it is checked for the same leak rather than
            // assumed safe because it is behind a click.
            $dialog = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $monday->addDays(2)->toDateString())
                ->html();

            foreach (['777,777.00', '777777.00', '777777', '777 777'] as $leak) {
                if (str_contains($board, $leak) || str_contains($dialog, $leak)) {
                    throw new Exception("the board printed the quote as {$leak}");
                }
            }
        });

        $this->attempt('a read-only seat sees the grid but no way to write into it', function () use ($workshop, $monday): void {
            $html = (string) Livewire::actingAs($workshop)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->html();

            if (! str_contains($html, 'B 941 OPR')) {
                throw new Exception('the workshop manager was locked out of a screen the design gives trips.view on');
            }

            // The affordance, not the words "Plan a trip": the plan dialog's *title* is that same string
            // and is markup for every seat, so matching on the label would pass for a screen that offers
            // nothing and fail for one that correctly hides it.
            if (str_contains($html, 'openPlan(')) {
                throw new Exception('the board offered a planning button to a role without trips.plan');
            }
        });

        $this->attempt('a user with no operational permission is refused the route, not served an empty board', function () use ($reader, $monday): void {
            $status = $this->actingAs($reader, 'web')->get("/dispatch?week={$monday->toDateString()}")->getStatusCode();

            if ($status !== 403) {
                throw new Exception("expected 403, got {$status}");
            }

            echo "  ok    (unpermissioned user answered {$status})\n";
        });

        echo "\n== Click-to-assign: an empty cell plans a trip and the cell reports it ==\n";

        $this->attempt('the cell opens the dialog, and planning writes through CreateTrip + PlanTrip', function () use ($owner, $job, $truck, $driver, $trailer, $monday): void {
            $day = $monday->addDays(2)->toDateString();

            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $day)
                ->assertDispatched('open-dispatch-plan')
                ->set('planJobId', $job->id)
                ->set('planDriverId', (string) $driver->id)
                ->set('planTrailerId', (string) $trailer->id)
                ->call('planTrip')
                ->assertHasNoErrors('plan');

            /** @var Trip|null $made */
            $made = Trip::query()->where('truck_id', $truck->id)->where('driver_id', $driver->id)->latest('created_at')->first();

            if ($made === null) {
                throw new Exception('the plan dialog saved nothing');
            }

            $this->tripIds[] = $made->id;

            if ($made->status !== TripStatus::Planned) {
                throw new Exception('the cell planned to '.$made->status->value.', not planned');
            }

            if ((int) $made->trailer_id !== $trailer->id) {
                throw new Exception('the trailer chosen in the dialog was not stored');
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->html();

            foreach ([$made->trip_no, 'OPR Board Driver', $job->job_no] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the cell does not show {$needle}");
                }
            }

            if (! str_contains($html, 'Planned')) {
                throw new Exception('the cell shows no status badge');
            }
        });

        $this->attempt('an enquiry is not dispatchable work: planning against it refuses on screen', function () use ($owner, $enquiry, $truck, $driver, $monday): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $monday->addDays(3)->toDateString())
                ->set('planJobId', $enquiry->id)
                ->set('planDriverId', (string) $driver->id)
                ->call('planTrip')
                ->assertHasErrors('plan');

            if (Trip::query()->where('job_id', $enquiry->id)->exists()) {
                throw new Exception('a trip was created against an enquiry');
            }
        });

        echo "\n== DoD on the board: the expired licence is visible before the click, and the click refuses ==\n";

        $this->attempt('the cell carries the findings, and dispatch is refused from the dialog', function () use ($owner, $lapsed, $job, $truck, $monday): void {
            // The lapsed driver is *listed*, labelled and selectable. The plan dialog does not hide a
            // driver the gate would refuse, because a dispatcher who cannot find a name assumes the board
            // is broken; the refusal belongs at the commitment, and this proves it stays there.
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $monday->addDays(4)->toDateString())
                ->set('planJobId', $job->id)
                ->set('planDriverId', (string) $lapsed->id)
                ->call('planTrip')
                ->assertHasNoErrors('plan');

            /** @var Trip|null $planned */
            $planned = Trip::query()->where('driver_id', $lapsed->id)->latest('created_at')->first();

            if ($planned === null) {
                throw new Exception('the board refused to plan the lapsed driver at all');
            }

            $this->tripIds[] = $planned->id;

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $planned->id)
                ->html();

            if (! str_contains($html, 'thing(s) to fix')) {
                throw new Exception('the cell offered the trip with no findings count beside it');
            }

            if (! str_contains($html, 'cannot leave as planned')) {
                throw new Exception('the dialog does not say the trip cannot leave');
            }

            if (! str_contains(strtolower($html), 'licence')) {
                throw new Exception('the dialog does not name the licence as the problem');
            }

            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $planned->id)
                ->call('dispatchTrip')
                ->assertHasErrors('trip');

            if ($planned->fresh()->status !== TripStatus::Planned) {
                throw new Exception('the refused dispatch still moved the trip to '.$planned->fresh()->status->value);
            }

            if ((int) DB::table('vehicle_assignments')->where('trip_id', $planned->id)->count() !== 0) {
                throw new Exception('a refused dispatch reserved a truck');
            }
        });

        $this->attempt('the plan dialog puts the band on the option, and keeps the reason after the list closes', function () use ($owner, $truck, $monday, $lapsed, $driver): void {
            $day = $monday->addDays(4)->toDateString();

            $before = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $day)
                ->html();

            // The gate itself is proven above; what this proves is that the same answer reaches the
            // dispatcher's eye before the click, in the two ways a list can say it.
            $option = $this->driverOption($before, (int) $lapsed->id);

            if (! str_contains($option, 'text-danger')) {
                throw new Exception('the lapsed driver was offered with no tone on the option: '.$option);
            }

            if (! str_contains($option, 'licence Expired')) {
                throw new Exception('the option carried a colour and no reason: '.$option);
            }

            $after = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $day)
                ->set('planDriverId', (string) $lapsed->id)
                ->html();

            if (! str_contains($after, 'role="status"') || ! str_contains($after, 'days ago')) {
                throw new Exception('choosing the lapsed driver printed no standing line with its distance');
            }

            $clean = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openPlan', $truck->id, $day)
                ->set('planDriverId', (string) $driver->id)
                ->html();

            if (str_contains($clean, 'role="status"')) {
                throw new Exception('a driver whose papers cover the day was printed as a paper problem');
            }
        });

        echo "\n== The same dialog commits once the papers are clean, and the reservation follows ==\n";

        $this->attempt('dispatch from the board moves planned → assigned and holds the vehicle', function () use ($owner, $job, $truck, $driver, $monday): void {
            $trip = $this->planOnBoard($owner, $job, $truck, $driver, $monday->addDays(5)->toDateString());

            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $trip->id)
                ->assertDispatched('open-dispatch-trip')
                ->call('dispatchTrip')
                ->assertHasNoErrors('trip')
                ->assertDispatched('toast');

            if ($trip->fresh()->status !== TripStatus::Assigned) {
                throw new Exception('the board left the trip at '.$trip->fresh()->status->value);
            }

            if (! DB::table('vehicle_assignments')->where('trip_id', $trip->id)->whereNull('to_at')->exists()) {
                throw new Exception('assigned with no live reservation — the board would double-book the truck');
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->html();

            if (! str_contains($html, $trip->trip_no)) {
                throw new Exception('the assigned trip vanished from its cell');
            }

            if (! str_contains($html, 'Assigned')) {
                throw new Exception('the cell still badges the trip as planned after the dispatch');
            }

            $this->assignedTripId = $trip->id;
        });

        echo "\n== a hold is a blocker with a reason, and it frees the truck ==\n";

        $this->attempt('a blank reason is a field error, and a stated one puts the trip on hold', function () use ($owner, $monday): void {
            if ($this->assignedTripId === '') {
                throw new Exception('the dispatch test left no assigned trip to hold');
            }

            $trip = Trip::query()->findOrFail($this->assignedTripId);

            // The same truck already holds this trip's open reservation: planning a second trip on it and
            // dispatching that would be refused by Fleet, not by the hold, and would test the wrong thing.
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $trip->id)
                ->call('holdTrip')
                ->assertHasErrors('holdReason');

            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $trip->id)
                ->set('holdReason', 'Awaiting the insurance certificate scan')
                ->call('holdTrip')
                ->assertHasNoErrors('holdReason')
                ->assertHasNoErrors('trip');

            if ($trip->fresh()->status !== TripStatus::OnHold) {
                throw new Exception('the hold left the trip at '.$trip->fresh()->status->value);
            }

            if (DB::table('vehicle_assignments')->where('trip_id', $trip->id)->whereNull('to_at')->exists()) {
                throw new Exception('a held trip still holds the truck — counts a held unit as free');
            }

            $this->heldTripId = $trip->id;
        });

        $this->attempt('the same control on a held trip releases it, without asking for a new reason', function () use ($owner, $monday): void {
            if ($this->heldTripId === '') {
                throw new Exception('the hold test did not leave a held trip to release');
            }

            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $this->heldTripId)
                ->call('holdTrip')
                ->assertHasNoErrors('trip');

            if (Trip::query()->find($this->heldTripId)?->status !== TripStatus::Planned) {
                throw new Exception('releasing the hold did not return the trip to planned');
            }
        });

        echo "\n== Tenancy on the wire ==\n";

        $this->attempt("another company's trip is neither listed nor actionable on this board", function () use ($owner, $truck, $driver, $monday): void {
            $foreign = $this->makeForeignTrip($monday->addDays(2)->toDateString(), $truck->id, $driver->id);
            $this->jobIds[] = $foreign['job_id'];

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->html();

            if (str_contains($html, $foreign['trip_no'])) {
                throw new Exception('the board rendered another tenant\'s trip — the grid is not company-scoped');
            }

            // The id is not on the board, but it is guessable: the dialog must answer with an error, not
            // by acting on a row the scoped query cannot see.
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $monday->toDateString()])
                ->call('openTrip', $foreign['id'])
                ->call('dispatchTrip')
                ->assertHasErrors('trip');

            if (DB::table('trips')->where('id', $foreign['id'])->value('status') !== TripStatus::Planned->value) {
                throw new Exception('the dialog moved a trip across tenants');
            }

            $this->tripIds[] = $foreign['id'];
        });

        echo "\n== The board is empty when there is nothing to dispatch ==\n";

        $this->attempt('a company with no trucks says so instead of showing a header row', function (): void {
            CurrentCompany::force(2);

            $html = (string) Livewire::test('pages::operations.dispatch')->html();

            CurrentCompany::force($this->ownerCompany ?? 1);

            if (! str_contains($html, 'No trucks')) {
                throw new Exception('company 2 with no fleet did not get the empty state');
            }
        });

        echo "\n== The jobs board: The decisions, driven through its own buttons ==\n";

        $this->pageContains($owner, '/jobs', $job->job_no, 'the register lists the accepted fixture job');
        $this->pageContains($dispatcher, '/jobs', $job->job_no, 'a dispatcher may open the jobs board (the design gives the row C/C)');

        $this->attempt('a seat without jobs.view is refused the register', function () use ($workshop): void {
            $status = $this->actingAs($workshop, 'web')->get('/jobs')->getStatusCode();

            if ($status !== 403) {
                throw new Exception("expected 403, got {$status}");
            }
        });

        $this->attempt('the board prints the derived badges from the trips, and no column stores them', function () use ($owner, $job): void {
            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->set('search', $job->job_no)
                ->html();

            if (! str_contains($html, 'trip')) {
                throw new Exception('the row carries no derived trip count (the design answers it from the children)');
            }

            // The engine-level half of the rule: The design refuses to store these four, so the schema must
            // not offer a column for them — a screen cannot be tricked into a stale badge.
            $columns = array_column(DB::select('show columns from freight_jobs'), 'Field');

            foreach (['all_trips_completed', 'ready_to_invoice', 'invoiced', 'settled'] as $derived) {
                if (in_array($derived, $columns, true)) {
                    throw new Exception("freight_jobs stores {$derived} — the design says it must not");
                }
            }
        });

        $this->attempt('the status filter narrows the register and keeps the rows that match', function () use ($owner, $job): void {
            // `CreateTrip` moves an accepted job to `in_execution`, so that is the status a fixture
            // with a trip on it carries by the time the register is filtered.
            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->set('statusFilter', 'in_execution')
                ->html();

            if (! str_contains($html, $job->job_no)) {
                throw new Exception('the job in execution vanished when the register was filtered to in_execution');
            }
        });

        $this->attempt('the create dialog opens an enquiry, and a blank customer is a field error', function () use ($owner, $customer, $route, $monday): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openCreate')
                ->assertDispatched('open-job-form')
                ->set('formCustomerId', (string) $customer->id)
                ->set('formRouteId', (string) $route->id)
                ->set('formCommodity', 'board sweep pallets')
                ->set('formTonnes', '9.5')
                ->set('formLoadOn', $monday->addDays(9)->toDateString())
                ->set('formDeliveryOn', $monday->addDays(12)->toDateString())
                ->set('formNotes', 'OPR board sweep job')
                ->call('saveJob')
                ->assertHasNoErrors('form');

            /** @var Job|null $made */
            $made = Job::query()->where('notes', 'OPR board sweep job')->first();

            if ($made === null) {
                throw new Exception('the create dialog saved nothing');
            }

            $this->jobIds[] = $made->id;

            if ($made->status !== JobStatus::Enquiry) {
                throw new Exception('a new job is '.$made->status->value.', not an enquiry');
            }

            if (! str_contains($made->job_no, 'JOB-')) {
                throw new Exception('the job has no allocated reference ('.$made->job_no.')');
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->set('search', $made->job_no)
                ->html();

            if (! str_contains($html, $made->job_no)) {
                throw new Exception('the register does not list the job it just created');
            }

            $refused = Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->set('formRouteId', (string) $route->id)
                ->call('saveJob')
                ->assertHasErrors('formCustomerId');

            // The refusal used to be a hand-written `@error` block sitting beside the control; `x-ui.select`
            // owns it now, so the sweep counts the sentence. Two copies is the bug this replaces, zero means
            // the shared `$errors` bag never reached the component.
            $markup = (string) $refused->html();
            $message = $refused->instance()->getErrorBag()->first('formCustomerId');
            $times = $message === '' ? 0 : substr_count($markup, $message);

            if ($times !== 1) {
                throw new Exception("the customer refusal renders {$times} times — once is the field, twice is the page repeating it");
            }

            if (! str_contains($markup, 'aria-describedby="form-customer-error"')) {
                throw new Exception('the customer select says nothing is wrong — no aria-invalid, no described-by pointing at its own message');
            }
        });

        $this->attempt('the quote dialog prices it, and the register reads the figure back', function () use ($owner): void {
            $made = Job::query()->where('notes', 'OPR board sweep job')->firstOrFail();

            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openQuote', $made->id)
                ->assertDispatched('open-job-quote')
                ->set('quoteRateType', RouteRateType::PerTrip->value)
                ->set('quoteAmount', '4200.00')
                ->call('saveQuote')
                ->assertHasNoErrors('quote');

            $quoted = $made->fresh();

            if ($quoted->currency_code !== CurrentCompany::model()->base_currency_code) {
                throw new Exception('the quote did not land in the company currency');
            }

            if ((int) $quoted->quoted_amount !== 420_000) {
                throw new Exception('the quote is '.$quoted->quoted_amount.' minor units, not 420000 (minor units, no float)');
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->set('search', $quoted->job_no)
                ->html();

            if (! str_contains($html, '4,200.00')) {
                throw new Exception('the register does not print the quoted amount');
            }
        });

        $this->attempt('a foreign quote with no rate is refused on screen, not silently converted', function () use ($owner): void {
            $made = Job::query()->where('notes', 'OPR board sweep job')->firstOrFail();

            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openQuote', $made->id)
                ->set('quoteCurrency', 'USD')
                ->set('quoteAmount', '100')
                ->call('saveQuote')
                ->assertHasErrors('quote');
        });

        $this->attempt('accepting an unpriced enquiry refuses with the reason, and accepting a priced job works', function () use ($owner, $enquiry): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openDecision', $enquiry->id, 'accept')
                ->call('commitDecision')
                ->assertHasErrors('decision');

            if ($enquiry->fresh()->status !== JobStatus::Enquiry) {
                throw new Exception('the refused accept still moved the enquiry');
            }

            $priced = Job::query()->where('notes', 'OPR board sweep job')->firstOrFail();

            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openDecision', $priced->id, 'accept')
                ->call('commitDecision')
                ->assertHasNoErrors('decision');

            if ($priced->fresh()->status !== JobStatus::Accepted) {
                throw new Exception('accept left the job at '.$priced->fresh()->status->value);
            }
        });

        $this->attempt('a decline needs a reason, and the reason is kept on the trail', function () use ($owner, $enquiry): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openDecision', $enquiry->id, 'decline')
                ->call('commitDecision')
                ->assertHasErrors('decisionReason');

            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openDecision', $enquiry->id, 'decline')
                ->set('decisionReason', 'Customer went with another carrier')
                ->call('commitDecision')
                ->assertHasNoErrors();

            // `operational_events.aggregate_type` is the noun, not the class (one trail query groups
            // by what a person recognises), so the lookup is 'job' rather than the FQCN.
            $trail = DB::table('operational_events')
                ->where('aggregate_type', 'job')
                ->where('aggregate_id', $enquiry->id)
                ->where('to_state', 'declined')
                ->first();

            if ($trail === null) {
                throw new Exception('the decline wrote no operational event');
            }

            if (! str_contains((string) $trail->reason, 'another carrier')) {
                throw new Exception('the event trail lost the reason the operator typed');
            }
        });

        $this->attempt('closing is refused while the job still has a trip on the road', function () use ($owner, $job): void {
            Livewire::actingAs($owner)
                ->test('pages::operations.jobs')
                ->call('openDecision', $job->id, 'close')
                ->set('decisionReason', 'Trying to close too early')
                ->call('commitDecision')
                ->assertHasErrors('decision');

            if ($job->fresh()->status === JobStatus::Closed) {
                throw new Exception('the board closed a job whose trips are still open');
            }
        });

        echo "\n== The trip detail: schedule, gate, trail ==\n";

        $this->attempt('the trip re-dispatches after its hold, and its own page prints the trail', function () use ($owner, $driver): void {
            if ($this->assignedTripId === '') {
                throw new Exception('no trip left to open the detail for');
            }

            $trip = Trip::query()->findOrFail($this->assignedTripId);

            // A released hold has to clear the whole gate again — that is the point of the hold — and
            // the board is where the operator does it.
            Livewire::actingAs($owner)
                ->test('pages::operations.dispatch', ['start' => $trip->planned_departure_at->timezone(CurrentCompany::timezone())->toDateString()])
                ->call('openTrip', $trip->id)
                ->call('dispatchTrip')
                ->assertHasNoErrors('trip');

            $this->actingAs($owner, 'web')->get("/trips/{$trip->id}")->assertOk()->assertSee($trip->trip_no, false);

            $html = (string) Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $trip])->html();

            foreach (['B 941 OPR', 'OPR Board Driver', 'Assigned'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the detail page is missing {$needle}");
                }
            }

            // The trail is the screen's spine: every move, in the order the engine wrote them.
            $moves = Trip::query()->find($trip->id) === null ? 0 : (int) DB::table('operational_events')
                ->where('aggregate_type', 'trip')
                ->where('aggregate_id', $trip->id)
                ->count();

            if ($moves < 3) {
                throw new Exception("expected draft → planned → assigned on the trail, got {$moves} rows");
            }

            if (! str_contains($html, 'planned') || ! str_contains($html, 'assigned')) {
                throw new Exception('the trail is rendered but does not name the states it walked');
            }

            unset($driver);
        });

        $this->attempt('an empty manifest cannot be loaded, and a load without its waybill cannot either', function () use ($owner, $trailer): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('markLoaded')
                ->assertHasErrors('load');

            $waybill = Document::forceCreate([
                'type' => DocumentType::DeliveryNote->value,
                'title' => 'OPR waybill for the board sweep',
                'lifecycle' => Lifecycle::Current->value,
                'retention_class' => RetentionClass::Operational->value,
                'morph_type' => 'trip',
                'morph_id' => $trip->id,
            ]);

            // A line with no paper on it: the gate names it, because "the manifest is short" is not an
            // answer an operator can act on.
            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('loadCommodity', 'pallets without paper')
                ->set('loadTonnes', '4')
                ->call('addLoad')
                ->assertHasNoErrors('load');

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('markLoaded')
                ->assertHasErrors('load');

            $refusal = mb_strtolower((string) Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('markLoaded')
                ->assertHasErrors('load')
                ->html());

            if (! str_contains($refusal, 'waybill')) {
                throw new Exception('the refusal did not name the missing waybill: '.mb_substr(strip_tags($refusal), 0, 200));
            }

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('loadCommodity', 'pallets with paper')
                ->set('loadTonnes', '6')
                ->set('loadCubic', '18')
                ->set('loadConsignee', 'OPR Depot')
                ->set('loadStopSequence', '1/1')
                ->set('loadWaybill', (string) $waybill->id)
                ->call('addLoad')
                ->assertHasNoErrors('load');

            // Measures against the weaker of the two limits: this trailer carries 30 t, so 40 t of
            // freight is refused even though the truck is rated to 32 t and would refuse it too.
            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('loadCommodity', 'over the trailer')
                ->set('loadTonnes', '40')
                ->set('loadWaybill', (string) $waybill->id)
                ->call('addLoad')
                ->assertHasNoErrors('load');

            $refused = mb_strtolower((string) Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('markLoaded')
                ->assertHasErrors('load')
                ->html());

            // The truck is rated 32 t and the trailer 30 t; Measures against the weaker, so the
            // screen has to say which one stopped it.
            if (! str_contains($refused, 'trailer')) {
                throw new Exception('the overloading refusal did not name the trailer: '.mb_substr(strip_tags($refused), 0, 200));
            }

            $paperless = Load::query()->where('trip_id', $trip->id)->where('commodity', 'pallets without paper')->firstOrFail();
            $over = Load::query()->where('trip_id', $trip->id)->where('commodity', 'over the trailer')->firstOrFail();

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('removeLoad', (int) $over->id)
                ->assertHasNoErrors('load');

            // The waybill-less line is the last thing standing between the trip and `loaded`.
            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('removeLoad', (int) $paperless->id)
                ->assertHasNoErrors('load');

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('markLoaded')
                ->assertHasNoErrors('load');

            if (Trip::query()->find($trip->id)?->status !== TripStatus::Loaded) {
                throw new Exception('the gate passed but the trip did not reach loaded');
            }

            unset($trailer);
        });

        $this->attempt('departure needs an odometer that moves forward, and the road edges follow', function () use ($owner): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('departOdometer', '1000')
                ->call('depart')
                ->assertHasErrors('depart');

            if (Trip::query()->find($trip->id)?->status === TripStatus::Departed) {
                throw new Exception('a backwards odometer still departed the trip');
            }

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('departOdometer', '150100')
                ->set('departHours', '410.5')
                ->call('depart')
                ->assertHasNoErrors('depart');

            $departed = Trip::query()->findOrFail($trip->id);

            if ($departed->status !== TripStatus::Departed || (int) $departed->odometer_start_km !== 150_100) {
                throw new Exception('the departure did not record the odometer it was given');
            }

            foreach (['in_transit', 'arrived', 'pod_pending', 'delivered'] as $edge) {
                Livewire::actingAs($owner)
                    ->test('pages::operations.detail', ['trip' => $trip])
                    ->call('advance', $edge)
                    ->assertHasNoErrors('advance');
            }

            if (Trip::query()->find($trip->id)?->status !== TripStatus::Delivered) {
                throw new Exception('the screen did not walk the machine to delivered');
            }

            // The edges that need a fact or another door must not be reachable from here, and must say who
            // owns them — the message is the deliverable, not just the refusal.
            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('advance', 'closed')
                ->assertHasErrors('advance');
        });

        $this->attempt('completion takes the arrival odometer, releases the truck, and the trip then closes', function () use ($owner, $truck): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('endOdometer', '150000')
                ->call('complete')
                ->assertHasErrors('complete');

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('endOdometer', '150940')
                ->set('endHours', '421.0')
                ->call('complete')
                ->assertHasNoErrors('complete');

            $completed = Trip::query()->findOrFail($trip->id);

            if ($completed->status !== TripStatus::Completed) {
                throw new Exception('completion left the trip at '.$completed->status->value);
            }

            if (DB::table('vehicle_assignments')->where('trip_id', $completed->id)->whereNull('to_at')->exists()) {
                throw new Exception('a completed trip still holds the truck open-ended');
            }

            if (! str_contains((string) $truck->fresh()->status->value, 'available')) {
                throw new Exception('the truck came back as '.$truck->fresh()->status->value);
            }

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('closeTrip')
                ->assertHasNoErrors('close');

            $closed = Trip::query()->findOrFail($trip->id);

            if ($closed->status !== TripStatus::Closed) {
                throw new Exception('close left the trip at '.$closed->status->value);
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->html();

            if (! str_contains($html, 'Closed')) {
                throw new Exception('the header still badges a closed trip as something else');
            }

            if (! str_contains($html, '150,940')) {
                throw new Exception('the odometer the driver reported is not on the page');
            }
        });

        $this->attempt('a closed trip re-opens only with a reason, and the reason lands on the trail', function () use ($owner): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);

            $this->withSession(['auth.password_confirmed_at' => time()]);

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->call('reopen')
                ->assertHasErrors('reason');

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('reason', 'The POD photo was the wrong load')
                ->call('reopen')
                ->assertHasNoErrors('reason');

            if (Trip::query()->find($trip->id)?->status !== TripStatus::Reopened) {
                throw new Exception('re-open left the trip at '.Trip::query()->find($trip->id)?->status->value);
            }

            $reason = DB::table('operational_events')
                ->where('aggregate_type', 'trip')
                ->where('aggregate_id', $trip->id)
                ->where('to_state', 'reopened')
                ->value('reason');

            if (! str_contains((string) $reason, 'wrong load')) {
                throw new Exception('the trail lost the reason the operator typed');
            }
        });

        echo "\n== the money on the trip page: three abilities, none widening another ==\n";

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        $this->attempt('an accountant records a cost through the page, and the projection moves with the posting', function () use ($accountant, $baseCurrency): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $today = CarbonImmutable::now(CurrentCompany::timezone())->toDateString();
            $cost = static fn () => Livewire::actingAs($accountant)->test('pages::operations.detail', ['trip' => $trip]);

            // The form's own bag: a zero amount is refused beside the button, not on the next screen.
            $cost()
                ->set('costType', 'fuel')
                ->set('costAmount', '0.00')
                ->set('costCurrency', $baseCurrency)
                ->set('costOccurredOn', $today)
                ->call('recordCost')
                ->assertHasErrors('cost');

            $cost()
                ->set('costType', 'fuel')
                ->set('costAmount', '250.00')
                ->set('costCurrency', $baseCurrency)
                ->set('costRate', '1')
                ->set('costRateSource', 'manual')
                ->set('costPaidWith', 'bank')
                ->set('costOccurredOn', $today)
                ->set('costDescription', 'OPR board fuel')
                ->call('recordCost')
                ->assertHasNoErrors('cost');

            $refreshed = Trip::query()->findOrFail($trip->id);

            if ($refreshed->fuel_cost_base !== 25_000 || $refreshed->direct_cost_base !== 25_000 || $refreshed->contribution_base !== -25_000) {
                throw new Exception('the page posted the cost but the projection did not move: '.json_encode([
                    $refreshed->fuel_cost_base, $refreshed->direct_cost_base, $refreshed->contribution_base,
                ]));
            }

            $entry = DB::table('gl_journal_entries')
                ->where('source_type', 'trip_cost')
                ->where('source_id', DB::table('trip_costs')->where('trip_id', $trip->id)->value('id'))
                ->first();

            if ($entry === null || (int) $entry->total_debit !== 25_000 || (int) $entry->total_credit !== 25_000) {
                throw new Exception('the screen recorded a cost with no balanced journal entry behind it');
            }

            $html = (string) $cost()->html();

            foreach (['Record a cost', 'Revenue posted', 'Direct costs', 'Contribution', 'OPR board fuel', '250,00'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the money section is missing {$needle} for the account that raised the cost");
                }
            }

            if (str_contains($html, 'must be a positive amount')) {
                throw new Exception('the refusal from the first attempt is still on the page after a successful one');
            }
        });

        // A chargeable the POD builder would have written: The design gives the dispatcher `invoices.view` and
        // not `trips.margin.view`, so this row must be readable without the margin beside it.
        $earningTrip = Trip::query()->findOrFail($this->assignedTripId);

        DB::table('chargeables')->insert([
            'id' => (string) Str::ulid(),
            'company_id' => $this->ownerCompany,
            'trip_id' => $earningTrip->id,
            'job_id' => $earningTrip->job_id,
            'customer_id' => DB::table('freight_jobs')->where('id', $earningTrip->job_id)->value('customer_id'),
            'type' => 'freight',
            'basis' => 'per_trip',
            'description' => 'OPR board earning',
            'currency_code' => $baseCurrency,
            'amount' => 77_777_700,
            'base_amount' => 77_777_700,
            'status' => 'ready',
            'source_uuid' => 'render:'.$earningTrip->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->attempt('a dispatcher reads the billing rows and is never shown the margin or the cost form', function () use ($dispatcher): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $html = (string) Livewire::actingAs($dispatcher)->test('pages::operations.detail', ['trip' => $trip])->html();

            foreach (['Ready to bill', 'Freight', 'Per trip', 'ready to bill', '777 777,00'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the dispatcher's page is missing {$needle}");
                }
            }

            foreach (['Revenue posted', 'Direct costs', 'Contribution', 'Record a cost', 'Record and post'] as $hidden) {
                if (str_contains($html, $hidden)) {
                    throw new Exception("a dispatcher was shown {$hidden} — the design keeps the margin off this seat");
                }
            }

            // The method is behind the domain gate too: hiding the button is not the whole rule.
            Livewire::actingAs($dispatcher)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('costType', 'toll')
                ->set('costAmount', '10.00')
                ->set('costCurrency', CurrentCompany::model()->base_currency_code)
                ->set('costOccurredOn', CarbonImmutable::now(CurrentCompany::timezone())->toDateString())
                ->call('recordCost')
                ->assertHasErrors('cost');

            if ((int) DB::table('trip_costs')->where('trip_id', $trip->id)->count() !== 1) {
                throw new Exception('a dispatcher POST wrote a cost row despite the refusal');
            }
        });

        echo "\n== the border: the register, then the four acts on the trip ==\n";

        $this->pageContains($owner, '/crossborder', 'Gates on file', 'the owner opens the gate register');

        $this->attempt('a gate is registered through the page, and a second attempt is refused by name', function () use ($owner): void {
            Livewire::actingAs($owner)
                ->test('pages::crossborder.index')
                ->set('postName', 'OPR Kazungula')
                ->set('postCountry', 'BW')
                ->set('postOpenFrom', '06:00')
                ->set('postOpenTo', '20:00')
                ->call('createPost')
                ->assertHasNoErrors('post')
                ->assertDispatched('close-border-post');

            if (! DB::table('border_posts')->where('name', 'OPR Kazungula')->exists()) {
                throw new Exception('the dialog registered no gate');
            }

            $html = (string) Livewire::actingAs($owner)->test('pages::crossborder.index')->html();

            foreach (['OPR Kazungula', '06:00–20:00'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the gate register is missing {$needle}");
                }
            }

            // Two rows for one gate would split its crossings; the Action refuses and the dialog says so.
            Livewire::actingAs($owner)
                ->test('pages::crossborder.index')
                ->set('postName', 'OPR Kazungula')
                ->set('postCountry', 'BW')
                ->call('createPost')
                ->assertHasErrors('post');

            if ((int) DB::table('border_posts')->where('name', 'OPR Kazungula')->count() !== 1) {
                throw new Exception('a refused duplicate left a second gate row');
            }
        });

        $this->attempt('the trip plans a crossing before it reaches the gate, and its section lists it', function () use ($owner): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $postId = (int) DB::table('border_posts')->where('name', 'OPR Kazungula')->value('id');

            Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('crossingPostId', (string) $postId)
                ->set('crossingDirection', 'entry')
                ->call('planCrossing')
                ->assertHasNoErrors('crossing')
                ->assertDispatched('toast');

            $crossing = DB::table('border_crossings')->where('trip_id', $trip->id)->first();

            if ($crossing === null) {
                throw new Exception('the page planned no crossing');
            }

            if ($crossing->status !== 'planned') {
                throw new Exception('the crossing was planned as '.$crossing->status);
            }

            $html = (string) Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $trip])->html();

            foreach (['Border gates', 'OPR Kazungula', 'planned', 'Plan the crossing'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the trip page is missing {$needle}");
                }
            }
        });

        $this->attempt('entered, exited, charged and finished through the page; the fee reaches the ledger', function () use ($owner, $baseCurrency): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $crossingId = (string) DB::table('border_crossings')->where('trip_id', $trip->id)->value('id');
            $page = static fn () => Livewire::actingAs($owner)->test('pages::operations.detail', ['trip' => $trip]);

            $page()->call('enterCrossing', $crossingId)->assertHasNoErrors('crossing');
            $page()->call('exitCrossing', $crossingId)->assertHasNoErrors('crossing');

            $page()
                ->call('openCharge', $crossingId)
                ->assertDispatched('open-border-charge')
                ->set('chargeKind', 'duty')
                ->set('chargeAmount', '450.00')
                ->set('chargeCurrency', $baseCurrency)
                ->set('chargeOccurredOn', CarbonImmutable::now(CurrentCompany::timezone())->toDateString())
                ->set('chargePaidWith', 'petty_cash')
                ->set('chargeDescription', 'OPR border duty')
                ->call('captureCharge')
                ->assertHasNoErrors('charge')
                ->assertDispatched('close-border-charge');

            $cost = DB::table('border_costs')->where('border_crossing_id', $crossingId)->first();

            if ($cost === null || $cost->kind !== 'duty' || $cost->currency_code !== $baseCurrency || (int) $cost->base_amount !== 45_000) {
                throw new Exception('the page captured no duty row with the expected money: '.json_encode($cost));
            }

            $entry = DB::table('gl_journal_entries')
                ->where('source_type', 'trip_cost')
                ->where('source_id', $cost->trip_cost_id)
                ->first();

            if ($entry === null || (int) $entry->total_debit !== 45_000 || (int) $entry->total_credit !== 45_000) {
                throw new Exception('the charge posted no balanced entry');
            }

            $lines = DB::table('gl_journal_lines as l')
                ->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.entry_id', $entry->id)
                ->pluck('a.code', 'l.direction');

            if (($lines['debit'] ?? null) !== '5400' || ($lines['credit'] ?? null) !== '1110') {
                throw new Exception('the charge posted '.json_encode($lines->all()).', not 5400 → 1110');
            }

            $page()->call('finishCrossing', $crossingId)->assertHasNoErrors('crossing');

            $status = DB::table('border_crossings')->where('id', $crossingId)->value('status');

            if ($status !== 'costs_captured') {
                throw new Exception('finishing left the crossing at '.$status);
            }

            // The projection moved with the posting: 250.00 of fuel plus 450.00 at the gate.
            $fresh = Trip::query()->findOrFail($trip->id);

            if ($fresh->border_cost_base !== 45_000 || $fresh->direct_cost_base !== 70_000) {
                throw new Exception('the rollup is '.json_encode([$fresh->border_cost_base, $fresh->direct_cost_base]).', not 45000/70000');
            }

            // Three moves, three events, in order.
            $states = DB::table('operational_events')
                ->where('aggregate_type', 'border_crossing')
                ->where('aggregate_id', $crossingId)
                ->orderBy('id')
                ->pluck('to_state')
                ->all();

            if ($states !== ['entered', 'exited', 'costs_captured']) {
                throw new Exception('the crossing trail reads '.json_encode($states));
            }

            $html = (string) $page()->html();

            foreach (['Border gates', 'OPR Kazungula', 'costs captured', '450,00'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the finished crossing is missing {$needle} on the trip page");
                }
            }
        });

        $this->attempt('the register lists the finished crossing, and its status filter narrows past it', function () use ($owner): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $register = static fn () => Livewire::actingAs($owner)->test('pages::crossborder.index');

            $html = (string) $register()->set('search', $trip->trip_no)->html();

            foreach ([$trip->trip_no, 'OPR Kazungula', 'costs captured', '450,00', '1 charge(s)'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the register is missing {$needle}");
                }
            }

            $filtered = (string) $register()->set('statusFilter', 'planned')->html();

            if (! str_contains($filtered, 'No crossings match')) {
                throw new Exception('the status filter still lists a costs-captured crossing under planned');
            }
        });

        echo "\n== on the border: the dispatcher reads the gate and cannot move or charge it ==\n";

        $this->pageContains($dispatcher, '/crossborder', 'Gates on file', 'a dispatcher may open the register (the design gives crossborder.view)');

        $this->attempt('the register offers no gate form to a seat without crossborder.manage', function () use ($dispatcher): void {
            $html = (string) Livewire::actingAs($dispatcher)->test('pages::crossborder.index')->html();

            if (! str_contains($html, 'OPR Kazungula')) {
                throw new Exception('the dispatcher cannot see the gates on file');
            }

            if (str_contains($html, 'Register a gate')) {
                throw new Exception('the register offered the gate form to a seat without crossborder.manage');
            }
        });

        $this->attempt('the trip shows the dispatcher the crossing, but no control that moves or charges it', function () use ($dispatcher): void {
            $html = (string) Livewire::actingAs($dispatcher)
                ->test('pages::operations.detail', ['trip' => Trip::query()->findOrFail($this->assignedTripId)])
                ->html();

            foreach (['Border gates', 'OPR Kazungula', 'costs captured', '450,00'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the dispatcher's page is missing {$needle}");
                }
            }

            foreach (['Plan the crossing', 'Add a charge', 'Finish the costs', 'Capture and post', 'Capture a border charge'] as $hidden) {
                if (str_contains($html, $hidden)) {
                    throw new Exception("a dispatcher was shown {$hidden} — the design keeps crossings at crossborder.view");
                }
            }
        });

        $this->attempt('the dispatcher may not plan a crossing through a hand-made POST either', function () use ($dispatcher): void {
            $trip = Trip::query()->findOrFail($this->assignedTripId);
            $postId = (int) DB::table('border_posts')->where('name', 'OPR Kazungula')->value('id');

            Livewire::actingAs($dispatcher)
                ->test('pages::operations.detail', ['trip' => $trip])
                ->set('crossingPostId', (string) $postId)
                ->call('planCrossing')
                ->assertHasErrors('crossing');

            if ((int) DB::table('border_crossings')->where('trip_id', $trip->id)->count() !== 1) {
                throw new Exception('a dispatcher POST planned a second crossing');
            }
        });

        echo "\n== / R6 the live map: a truck on the road, the simulator, and what the page prints ==\n";

        $this->attempt('a trip goes on the road and the simulator files five fixes under it', function () use ($owner, $customer, $truck, $driver, $monday): void {
            // The border section ends acting as the dispatcher, and the truck's state event is written
            // against whoever is on the guard — so the fixture is created as the owner, who owns it.
            Auth::login($owner);
            CurrentCompany::force($this->ownerCompany ?? 1);

            // A corridor whose endpoints are real places, so the simulator walks the place table
            // instead of the --from/--to escape hatch (which verify-telematics proves separately).
            $corridor = (app(CreateRoute::class))(new RouteDto(
                code: 'OPR-GBE-FRW',
                origin: 'Gaborone',
                destination: 'Francistown',
                originCountryCode: 'BW',
                destinationCountryCode: 'BW',
                distanceKm: '430',
            ));

            // A distinctive quote: if any figure from the job can reach a `gps.view` screen, this is it.
            $job = (app(CreateJob::class))(new JobDto(
                customerId: $customer->id,
                routeId: $corridor->id,
                commodity: 'tracked pallets',
                tonnes: 14.0,
                requiredLoadOn: $monday->toDateString(),
                requiredDeliveryOn: $monday->addDays(2)->toDateString(),
                notes: 'OPR gps job',
            ), 1);
            $this->jobIds[] = $job->id;

            $job = (app(QuoteJob::class))($job, new JobQuoteDto(
                rateType: RouteRateType::PerTrip,
                amountMajor: '555555',
                currencyCode: CurrentCompany::model()->base_currency_code,
            ), 1);
            $job = (app(AcceptJob::class))($job, 1);

            $trip = (app(CreateTrip::class))($job, 1);
            $this->tripIds[] = $trip->id;

            $trip = (app(PlanTrip::class))($trip, new TripDto(
                jobId: $job->id,
                truckId: $truck->id,
                driverId: $driver->id,
                plannedDepartureAt: $monday->toDateTimeString(),
            ), 1);
            $trip = (app(DispatchTrip::class))($trip->fresh(), 1);
            $trip = (app(DepartTrip::class))($trip->fresh(), 151_000, 430.0, 1);

            $exit = Artisan::call('gps:simulate', ['trip' => $trip->fresh()->trip_no, '--pings' => 5]);
            $output = str_replace("\n", ' ', Artisan::output());

            if ($exit !== 0 || ! str_contains($output, '5 stored')) {
                throw new Exception('the simulator did not file five fixes: '.$output);
            }

            if ((int) DB::table('gps_positions')->where('trip_id', $trip->id)->count() !== 5) {
                throw new Exception('five pings produced '.DB::table('gps_positions')->where('trip_id', $trip->id)->count().' rows');
            }

            $this->gpsTripId = $trip->id;
        });

        $this->attempt('the map prints the truck, the trip and the fix — and no money from the job behind it', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/telematics')->assertOk()->getContent();

            foreach (['Live map', 'B 941 OPR', 'km/h', 'Schematic', 'wire:poll'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the live map is missing {$needle}");
                }
            }

            // `gps.view` is held by seats that may not read a price. The corridor is on the page;
            // the quote behind it is not.
            foreach (['555555', '5 555', '555 555'] as $forbidden) {
                if (str_contains($html, $forbidden)) {
                    throw new Exception("the live map leaked the job's quote ({$forbidden}) to a gps.view seat");
                }
            }
        });

        $this->attempt('selecting a truck draws its track, oldest first, with the provider message on each row', function () use ($owner): void {
            if ($this->gpsTripId === '') {
                throw new Exception('no tracked trip to select');
            }

            $component = Livewire::actingAs($owner)
                ->test('pages::telematics.live')
                ->set('trip', $this->gpsTripId);

            $html = (string) $component->html();

            foreach (['Fixed at (UTC)', 'SIM-', 'polyline', '5 fix(es) on file'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the track panel is missing {$needle}");
                }
            }

            // A prop name the component does not declare renders as an HTML attribute and styles nothing, and
            // lint-ui reading the source cannot see that — only a render can. So this checks that `numeric`
            // became the token class, that the caption the screen now promises is actually in the markup, and
            // that no word-prop leaked onto the element.
            if (! str_contains($html, '<caption class="sr-only">Every fix reported for the selected trip')) {
                throw new Exception('the track table renders without its accessible name');
            }

            if (! str_contains($html, 'fg-numeric')) {
                throw new Exception('no figure cell resolved to .fg-numeric — the columns do not line up on the decimal point');
            }

            if (str_contains($html, 'numeric="') || str_contains($html, 'identifier="')) {
                throw new Exception('a table cell prop leaked into the markup as an attribute instead of a class');
            }

            // The toggle has to close as well as open, or the panel is a trap on a polled screen.
            $component->call('selectTrip', $this->gpsTripId);

            if (str_contains((string) $component->html(), 'Fixed at (UTC)')) {
                throw new Exception('selecting the same trip again did not close the track');
            }
        });

        $this->attempt('a dispatcher reads the map, and a seat without gps.view is refused the route', function () use ($dispatcher, $reader): void {
            $this->actingAs($dispatcher, 'web')->get('/telematics')->assertOk()->assertSee('B 941 OPR', false);

            $status = $this->actingAs($reader, 'web')->get('/telematics')->getStatusCode();

            if ($status !== 403) {
                throw new Exception("a seat with no gps.view got {$status} from /telematics, not 403");
            }
        });

        $this->attempt('a company with no feed says so instead of drawing an empty grid', function (): void {
            CurrentCompany::force(2);

            $html = (string) Livewire::test('pages::telematics.live')->html();

            CurrentCompany::force($this->ownerCompany ?? 1);

            if (! str_contains($html, 'No positions yet')) {
                throw new Exception('company 2 with no fixes did not get the empty state');
            }

            if (str_contains($html, 'B 941 OPR')) {
                throw new Exception('company 2 read another tenant\'s trucks off the projection');
            }
        });

        echo "\n== one filled action per view, counted on the page a real state renders ==\n";

        // lint-architecture keeps the modules apart and lint-ui keeps the source honest about variants, but
        // only a render can say how many filled buttons a user is actually looking at: the static pass
        // cannot resolve `@if ($this->canDepart())` against `@if ($this->canComplete())`, and it has to treat
        // a dialog as its own view. Each of these is one real state of one real screen.
        $this->attempt('the screens that offer work each carry one filled action', function () use ($owner, $week): void {
            $paths = [
                "/dispatch?week={$week}",
                '/jobs',
                '/routes',
                '/crossborder',
                '/telematics',
                '/fleet',
                '/people',
            ];

            foreach ($paths as $path) {
                $response = $this->actingAs($owner, 'web')->get($path);
                $status = $response->getStatusCode();

                if ($status !== 200) {
                    throw new Exception("{$path} answered {$status}, so the count below is not the count a user sees");
                }

                $this->assertOneFilledAction((string) $response->getContent(), $path);
            }
        });

        $this->attempt('the trip page in its working state carries one filled action', function () use ($owner): void {
            if ($this->assignedTripId === '') {
                throw new Exception('no trip left to count');
            }

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::operations.detail', ['trip' => Trip::query()->findOrFail($this->assignedTripId)])
                ->html();

            $this->assertOneFilledAction($html, '/trips/{trip}');
        });

        echo "\n== the cross-phase rule: the phase ships dashboard figures, counted from real rows ==\n";

        $this->attempt('the overview carries the five stages in work order, and the planned count is the register\'s', function () use ($owner): void {
            $html = (string) Livewire::actingAs($owner)
                ->test('pages::dashboard.dashboard')
                ->html();

            // Redesigned 2026-11-03 (docs/HOME-DESIGN.md): the band is now `x-ui.pipeline`, and the one
            // "Jobs waiting on a decision" figure became the two stages actually keeps apart —
            // unpriced enquiries and quoted-and-unanswered. The labels moved; the rule did not.
            foreach ([
                'Enquiries',
                'Quoted, awaiting',
                'Planned, not dispatched',
                'On the road',
                'Proof outstanding',
            ] as $label) {
                if (! str_contains($html, $label)) {
                    throw new Exception("the overview is missing the {$label} stage");
                }
            }

            // Not a decoration, and not a label check either: the number under the stage has to be the
            // count the register would list. The stage's own slice of the HTML is searched, so a stray
            // "3" somewhere else on the page cannot pass it.
            $planned = (int) Trip::query()->where('status', TripStatus::Planned->value)->count();
            $road = (int) Trip::query()->whereIn('status', [
                TripStatus::Departed->value,
                TripStatus::InTransit->value,
                TripStatus::AtBorder->value,
                TripStatus::Arrived->value,
            ])->count();

            if ($planned === 0) {
                throw new Exception('the sweep left no planned trip to count — the assertion would pass on an empty screen');
            }

            foreach ([
                ['Planned, not dispatched', 'On the road', $planned],
                ['On the road', 'Proof outstanding', $road],
            ] as [$from, $to, $expected]) {
                $start = strpos($html, $from);
                $end = strpos($html, $to, $start + strlen($from));

                if ($start === false || $end === false) {
                    throw new Exception("the {$from} stage could not be isolated for counting");
                }

                $slice = substr($html, $start, $end - $start);

                if (! preg_match('/>\s*'.preg_quote((string) $expected, '/').'\s*</', $slice)) {
                    throw new Exception("the {$from} stage does not show the register's {$expected}");
                }
            }

            $this->actingAs($owner, 'web')->get('/dashboard')->assertOk()->assertSee('Planned, not dispatched', false);
        });

        $this->attempt('a seat that cannot open the jobs board is told which ability unlocks the card', function () use ($workshop): void {
            $html = (string) Livewire::actingAs($workshop)
                ->test('pages::dashboard.dashboard')
                ->html();

            if (! str_contains($html, 'jobs.view')) {
                throw new Exception('the masked jobs card did not name the ability (the mask-not-delete rule)');
            }

            // The trip cards stay live for this seat: The design gives Workshop Manager `trips.view`.
            if (! str_contains($html, 'On the road')) {
                throw new Exception('a seat with trips.view lost the trip figures it is entitled to read');
            }
        });
    }

    /**
     * A trip that belongs to company 2, assembled with raw inserts.
     *
     * Eloquent's tenancy trait rightly refuses a cross-company write, and the probe needs the row to
     * exist as *the other tenant's* — not to exercise the guard. The composite `trips_job_company_fk`
     * forces the job, route and customer to be company 2's too; the truck and driver are shared rows,
     * which is exactly the leak the board's scope has to survive.
     *
     * @return array{id: string, trip_no: string, job_id: string}
     */
    private function makeForeignTrip(string $day, int $truckId, int $driverId): array
    {
        if (! DB::table('companies')->where('id', 2)->exists()) {
            DB::table('companies')->insert([
                'id' => 2,
                'name' => 'Render OPR Second Company',
                'timezone' => 'Africa/Gaborone',
                'base_currency_code' => 'BWP',
                'fiscal_year_starts_on' => '2000-01-01',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $stamp = Carbon::now();
        $routeId = (int) DB::table('routes')->insertGetId([
            'company_id' => 2,
            'code' => 'OPR-FOREIGN',
            'origin' => 'Gaborone',
            'destination' => 'Remote',
            'origin_country_code' => 'BW',
            'destination_country_code' => 'BW',
            'distance_km' => 100,
            'active' => true,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $customerId = (int) DB::table('customers')->insertGetId([
            'company_id' => 2,
            'code' => 'OPR-FOREIGN',
            'name' => 'Render OPR Foreign Customer',
            'payment_terms' => 'net_30',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $jobId = (string) Str::ulid();

        DB::table('freight_jobs')->insert([
            'id' => $jobId,
            'company_id' => 2,
            'job_no' => 'JOB-99990',
            'customer_id' => $customerId,
            'route_id' => $routeId,
            'status' => 'accepted',
            'notes' => 'OPR foreign probe job',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $tripId = (string) Str::ulid();
        $tripNo = 'TRP-99990';

        DB::table('trips')->insert([
            'id' => $tripId,
            'company_id' => 2,
            'trip_no' => $tripNo,
            'job_id' => $jobId,
            'route_id' => $routeId,
            'status' => TripStatus::Planned->value,
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'planned_departure_at' => $day.'T06:00:00',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return ['id' => $tripId, 'trip_no' => $tripNo, 'job_id' => $jobId];
    }

    /**
     * Plan a clean trip through the board itself, so the assertions below ride on real clicks.
     */
    private function planOnBoard(User $user, Job $job, object $truck, object $driver, string $day): Trip
    {
        Livewire::actingAs($user)
            ->test('pages::operations.dispatch', ['start' => CarbonImmutable::parse($day)->startOfWeek()->toDateString()])
            ->call('openPlan', $truck->id, $day)
            ->set('planJobId', $job->id)
            ->set('planDriverId', (string) $driver->id)
            ->call('planTrip')
            ->assertHasNoErrors('plan');

        /** @var Trip $trip */
        $trip = Trip::query()->where('truck_id', $truck->id)->where('driver_id', $driver->id)->latest('created_at')->firstOrFail();
        $this->tripIds[] = $trip->id;

        return $trip;
    }

    private function staffAs(User $owner, ?RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render OPR Check '.($role?->label() ?? 'No Role');
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
     * Counts the filled actions a user actually sees, in a real render of one real state.
     *
     * This is the check `scripts/lint-ui.php` cannot make: it is textual and so cannot evaluate
     * `@if ($this->canDepart())`, and it has to treat a dialog as its own view. Neither limitation applies
     * once the page is rendered — the dialog markup is inert unless it carries `open`, and the branches have
     * resolved to whatever the trip's actual state allows. `bg-accent text-on-accent` is the primary
     * variant's signature in the component (the one filled action per view), and danger-solid does not match
     * it, so a confirm-step's destructive button is not counted as competing.
     */
    private function assertOneFilledAction(string $html, string $label): void
    {
        // Strip every dialog's content. A server render never opens one — `x-ui.dialog` opens client-side via
        // `showModal()` — so nothing inside a dialog is on screen, and each dialog is its own view with its
        // own affirmative action. Testing for an `open` attribute instead is a trap that already fired here:
        // the component's own attributes contain the word twice (`x-on:open-<name>.window`, `$el.open`), which
        // made every closed dialog look open and every page look like it had three filled actions.
        $visible = preg_replace('#<dialog\b[^>]*>.*?</dialog>#s', '', $html) ?? $html;

        // The component's own primary signature is `bg-accent text-on-accent`, shared with the app logo's
        // mark — so match the element, not the class run, or every page reports one phantom action.
        preg_match_all('/<(?:button|a)\b[^>]*class="[^"]*bg-accent text-on-accent[^"]*"[^>]*>(.{0,120})/s', $visible, $found);

        $count = count($found[0] ?? []);

        if ($count > 1) {
            // Named, because "3 filled actions" makes someone hunt; the labels are the fix list.
            $labels = array_map(
                static fn (string $tail): string => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($tail)) ?? ''), 0, 30) ?: '(icon)',
                $found[1] ?? [],
            );

            throw new Exception(sprintf(
                '%s: %d filled actions on screen together (%s) — the design allows one per view',
                $label,
                $count,
                implode(' | ', $labels),
            ));
        }
    }

    /** The markup one driver's option carries — id, tone, name and reason between the open and the close. */
    private function driverOption(string $html, int $driverId): string
    {
        // The view breaks the option's five attributes across lines for readability, so the search runs on a
        // flattened copy. Matching the raw HTML would report "no option printed" for a row that is there.
        $flat = preg_replace('/\s+/', ' ', $html) ?? $html;

        $start = strpos($flat, 'value="'.$driverId.'" class=');

        if ($start === false) {
            throw new Exception('the driver select printed no option for driver '.$driverId);
        }

        $end = strpos($flat, '</option>', $start);

        return substr($flat, $start, ($end === false ? strlen($flat) : $end) - $start);
    }

    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, fn () => $this->actingAs($user, 'web')->get($path)->assertOk()->assertSee($needle, false));
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;

            // PHPUnit's default renderer exports the whole page and then trips its own assertion outside
            // a test run; the message is the part that names the defect.
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";

            if (getenv('RENDER_OPS_TRACE') !== false) {
                echo $exception->getTraceAsString()."\n";
            }
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

        $this->rememberOwner();
        $this->purge($this->fixtureIds());
        $this->alignCounters('before the run, after an earlier run\'s debris');

        $this->runStartedAt = CarbonImmutable::now()->subSecond();

        try {
            $this->sweep();
        } finally {
            $this->purge($this->fixtureIds());
            $this->assertNothingSurvives();
            $this->alignCounters('after cleanup, so the nightly self-check sees only surviving rows');

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function rememberOwner(): void
    {
        // Read through the query builder: the owner's company is what every later step forces, and an
        // Eloquent lookup here would be filtered by the very scope the run is trying to establish.
        $company = DB::table('users')->where('email', config('fleet.identity.owner_email'))->value('company_id');

        if ($company === null) {
            throw new Exception('the seeded owner account is missing — seed the demo company before running this sweep');
        }

        $this->ownerCompany = (int) $company;
    }

    /**
     * The `job`/`trip` counters are compared against the rows by `documents:number-integrity`; leaving
     * this run's numbers spent with its fixtures deleted would report drift forever.
     */
    private function alignCounters(string $when): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
            $highest = DB::table($table)
                ->where('company_id', $this->ownerCompany ?? 1)
                ->pluck($column)
                ->map(static function (string $number): int {
                    $dash = strpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                })
                ->max() ?? 0;

            DB::table('number_sequences')
                ->where('company_id', $this->ownerCompany ?? 1)
                ->where('scope', $scope)
                ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        }

        CurrentCompany::forget();

        echo "  counters aligned to the surviving rows ({$when})\n";
    }

    /**
     * @return array{truck_ids: list<int>, driver_ids: list<int>, employee_ids: list<int>, job_ids: list<string>, trip_ids: list<string>, assignment_ids: list<int>}
     */
    private function fixtureIds(): array
    {
        $truckIds = array_values(array_unique(array_merge(
            $this->truckIds,
            DB::table('trucks')->where('registration_no', 'like', '%OPR')->pluck('id')->all(),
        )));

        $employeeIds = array_values(array_unique(array_merge(
            $this->employeeIds,
            DB::table('employees')->where('employee_no', 'like', 'EMP-OPR%')->pluck('id')->all(),
        )));

        $driverIds = DB::table('drivers')->whereIn('employee_id', $employeeIds ?: [0])->pluck('id')->all();

        $jobIds = array_values(array_unique(array_merge(
            $this->jobIds,
            DB::table('freight_jobs')->where('notes', 'like', 'OPR%')->pluck('id')->all(),
        )));

        $tripIds = array_values(array_unique(array_merge(
            $this->tripIds,
            $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all(),
        )));

        $assignmentIds = ($truckIds === [] && $driverIds === [])
            ? []
            : DB::table('vehicle_assignments')
                ->where(static fn ($q) => $q->whereIn('truck_id', $truckIds ?: [0])->orWhereIn('driver_id', $driverIds ?: [0]))
                ->pluck('id')
                ->all();

        return [
            'truck_ids' => array_map(static fn ($id): int => (int) $id, $truckIds),
            'driver_ids' => array_map(static fn ($id): int => (int) $id, $driverIds),
            'employee_ids' => array_map(static fn ($id): int => (int) $id, $employeeIds),
            'job_ids' => $jobIds,
            'trip_ids' => $tripIds,
            'assignment_ids' => array_map(static fn ($id): int => (int) $id, $assignmentIds),
        ];
    }

    /**
     * Delete by id list, and only when there is a list.
     *
     * `?: [0]` looks harmless but is not on a `char(26)` key: MySQL compares a ULID column against the
     * integer 0 numerically and aborts the whole statement with "Truncated incorrect DOUBLE value".
     *
     * @param  list<string|int>  $ids
     * @param  array<string, mixed>  $where
     */
    private function deleteIn(string $table, string $column, array $ids, array $where = []): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table($table)->where($where)->whereIn($column, $ids)->delete();
    }

    /** @param array{truck_ids: list<int>, driver_ids: list<int>, employee_ids: list<int>, job_ids: list<string>, trip_ids: list<string>, assignment_ids: list<int>} $ids */
    private function purge(array $ids): void
    {
        CurrentCompany::forget();

        /*
         * The money slice writes a journal entry through the page, and the design refuses to delete one without
         * the hatch. Lift it for exactly this run's entries, lower it in the same call, and let the purge
         * proceed on a ledger that is immutable again.
         */
        $costIds = $ids['trip_ids'] === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $ids['trip_ids'])->pluck('id')->all();
        // `source_id` is char(26) and a cost id is an integer; the strings keep MySQL from comparing a
        // char column to a number and truncating the statement.
        $costKeys = array_map(static fn (int|string $id): string => (string) $id, $costIds);
        $entryIds = $costKeys === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'trip_cost')
            ->whereIn('source_id', $costKeys)
            ->pluck('id')
            ->all();

        if ($entryIds !== []) {
            LedgerBarrier::withLiftedGuard('render-operations: remove this sweep\'s trip-cost entries', null, function () use ($entryIds): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            });
        }

        // The border rows point at the trip's costs both ways: `border_costs.trip_cost_id` is a foreign
        // key into `trip_costs`, so the crossing's costs go before the costs they name.
        $crossingIds = $ids['trip_ids'] === [] ? [] : DB::table('border_crossings')->whereIn('trip_id', $ids['trip_ids'])->pluck('id')->all();
        $this->deleteIn('border_costs', 'border_crossing_id', $crossingIds);
        $this->deleteIn('border_crossings', 'id', $crossingIds);

        // The map's rows go before the trucks they name: `gps_positions.truck_id` is a real foreign key,
        // and the projection cites a fix that is about to disappear.
        $this->deleteIn('latest_positions', 'trip_id', $ids['trip_ids']);
        $this->deleteIn('gps_positions', 'trip_id', $ids['trip_ids']);
        $this->deleteIn('latest_positions', 'truck_id', $ids['truck_ids']);
        $this->deleteIn('gps_positions', 'truck_id', $ids['truck_ids']);
        DB::table('gps_provider_accounts')->where('adapter', 'simulator')->delete();

        $this->deleteIn('chargeables', 'trip_id', $ids['trip_ids']);
        $this->deleteIn('trip_costs', 'trip_id', $ids['trip_ids']);
        $this->deleteIn('loads', 'trip_id', $ids['trip_ids']);
        $this->deleteIn('operational_events', 'aggregate_id', $ids['trip_ids'], ['aggregate_type' => 'trip']);

        // Company 2's rows have no company scope to break; the tenancy trait is bypassed on purpose here,
        // because a probe row that survives is a false conflict in every later run.
        $this->deleteIn('trips', 'id', $ids['trip_ids']);
        $this->deleteIn('freight_jobs', 'id', $ids['job_ids']);

        $this->deleteIn('vehicle_assignments', 'id', $ids['assignment_ids']);
        $this->deleteIn('odometer_readings', 'truck_id', $ids['truck_ids']);

        DB::table('documents')->where('title', 'like', 'OPR%')->delete();
        DB::table('routes')->where('code', 'like', 'OPR%')->delete();
        DB::table('customers')->where('code', 'like', 'OPR%')->delete();
        DB::table('border_posts')->where('name', 'like', 'OPR%')->delete();

        $this->deleteIn('drivers', 'id', $ids['driver_ids']);
        $this->deleteIn('employees', 'id', $ids['employee_ids']);
        $this->deleteIn('trucks', 'id', $ids['truck_ids']);
        DB::table('trailers')->where('registration_no', 'like', '%OPR')->delete();

        $checkIds = DB::table('users')->where('email', 'like', 'render-ops-check-%@fleetguard.local')->pluck('id')->all();
        $this->deleteIn('model_has_roles', 'model_id', $checkIds, ['model_type' => User::class]);
        // A probe seat that acted on the guard leaves its id on the trail it wrote (`operational_events`
        // has a real FK to `users`), and a truck's state event names the truck, not the trip — so the
        // sweeps above cannot reach it. The seat's own rows go with the seat.
        $this->deleteIn('operational_events', 'actor_id', $checkIds);
        // A counter remembers who last drew from it; the probe seats drew the run's job and trip
        // numbers, so that pointer must let go before the account can (the FK is a real one).
        if ($checkIds !== []) {
            DB::table('number_sequences')->whereIn('last_used_by', $checkIds)->update(['last_used_by' => null]);
        }
        $this->deleteIn('users', 'id', $checkIds);

        DB::table('companies')->where('name', 'Render OPR Second Company')->delete();

        // Audits and events name rows that no longer exist; left behind they read as history for a job
        // that was never closed. Scoped to orphans of these two aggregates so the demo company's own
        // trail is untouched.
        DB::table('audits')
            ->whereIn('auditable_type', [Trip::class, Job::class])
            ->whereRaw('`auditable_id` not in (select `id` from `trips`)')
            ->whereRaw('`auditable_id` not in (select `id` from `freight_jobs`)')
            ->delete();

        DB::table('operational_events')
            ->where('aggregate_type', 'trip')
            ->whereRaw('`aggregate_id` not in (select `id` from `trips`)')
            ->delete();

        DB::table('operational_events')
            ->where('aggregate_type', 'job')
            ->whereRaw('`aggregate_id` not in (select `id` from `freight_jobs`)')
            ->delete();

        DB::table('operational_events')
            ->where('aggregate_type', 'border_crossing')
            ->whereRaw('`aggregate_id` not in (select `id` from `border_crossings`)')
            ->delete();

        // The money rows leave their own trail. Matching by id is not possible here — `auditable_id`
        // is a bigint column and these models key on ULIDs — so the sweep is scoped to rows written
        // during this run, which is the only trail this script could have made.
        if ($this->runStartedAt !== null) {
            DB::table('audits')
                ->whereIn('auditable_type', [TripCost::class, Chargeable::class, BorderPost::class, BorderCrossing::class, BorderCost::class])
                ->where('created_at', '>=', $this->runStartedAt)
                ->delete();
        }
    }

    private function assertNothingSurvives(): void
    {
        $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-OPR%')->pluck('id')->all();
        $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'OPR%')->pluck('id')->all();

        $leftovers = [
            'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%OPR')->count(),
            'trailers' => (int) DB::table('trailers')->where('registration_no', 'like', '%OPR')->count(),
            'employees' => (int) count($employeeIds),
            'drivers' => $employeeIds === [] ? 0 : (int) DB::table('drivers')->whereIn('employee_id', $employeeIds)->count(),
            'jobs' => (int) count($jobIds),
            'trips' => $jobIds === [] ? 0 : (int) DB::table('trips')->whereIn('job_id', $jobIds)->count(),
            'routes' => (int) DB::table('routes')->where('code', 'like', 'OPR%')->count(),
            'customers' => (int) DB::table('customers')->where('code', 'like', 'OPR%')->count(),
            'probe company' => (int) DB::table('companies')->where('name', 'Render OPR Second Company')->count(),
            'check accounts' => (int) DB::table('users')->where('email', 'like', 'render-ops-check-%@fleetguard.local')->count(),
            // The money the page recorded: a surviving cost, a raw earning, or a ledger entry whose
            // cost is gone would all leave the trip's figures and the ledger disagreeing.
            'trip costs' => (int) DB::table('trip_costs')->where('description', 'like', 'OPR%')->count(),
            'chargeables' => (int) DB::table('chargeables')->where('source_uuid', 'like', 'render:%')->count(),
            'trip_cost entries' => (int) DB::table('gl_journal_entries')
                ->where('source_type', 'trip_cost')
                ->whereRaw('`source_id` not in (select `id` from `trip_costs`)')
                ->count(),
            'journal lines without an entry' => (int) DB::table('gl_journal_lines')
                ->whereRaw('`entry_id` not in (select `id` from `gl_journal_entries`)')
                ->count(),
            'border gates' => (int) DB::table('border_posts')->where('name', 'like', 'OPR%')->count(),
            'border crossings' => (int) DB::table('border_crossings')
                ->whereRaw('`trip_id` not in (select `id` from `trips`)')
                ->count(),
            'border costs' => (int) DB::table('border_costs')
                ->whereRaw('`border_crossing_id` not in (select `id` from `border_crossings`)')
                ->count(),
            'border events' => (int) DB::table('operational_events')
                ->where('aggregate_type', 'border_crossing')
                ->whereRaw('`aggregate_id` not in (select `id` from `border_crossings`)')
                ->count(),
            // The map's rows: a surviving fix or projection would put a truck on the live map that the
            // fleet register no longer has. The check is by orphan rather than by `SIM-%`, because the
            // demo fleet's own trip carries simulator fixes from its seeded run — counting every
            // simulator row would report the demo as this sweep's leftover.
            'gps fixes' => (int) DB::table('gps_positions')
                ->whereRaw('`truck_id` not in (select `id` from `trucks`)')
                ->orWhereRaw('`trip_id` is not null and `trip_id` not in (select `id` from `trips`)')
                ->count(),
            'gps projections' => (int) DB::table('latest_positions')
                ->whereRaw('`truck_id` not in (select `id` from `trucks`)')
                ->orWhereRaw('`trip_id` is not null and `trip_id` not in (select `id` from `trips`)')
                ->count(),
            'gps feeds' => (int) DB::table('gps_provider_accounts')->where('adapter', 'simulator')->count(),
        ];

        foreach ($leftovers as $what => $count) {
            echo $count === 0 ? "  ok    no {$what} survive\n" : "  FAIL  {$count} {$what} survive\n";

            if ($count !== 0) {
                $this->failures++;
            }
        }

        echo '  ok    the demo fleet is intact: '.DB::table('trucks')->count()." trucks registered\n";
    }
}

$sweep = new OperationsRenderSweep('sweep');

try {
    $sweep->drive();
} catch (Throwable $crashed) {
    // A throw that escapes `drive()` otherwise dies silently — the app's exception handler is torn down
    // in the `finally`, so nothing is left to print it. Report it as the failure it is.
    echo "\n  FAIL  the sweep stopped — ".$crashed::class.': '.$crashed->getMessage()."\n";
    echo $crashed->getTraceAsString()."\n";

    $sweep->failures++;
}

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
