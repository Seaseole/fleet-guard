<?php

declare(strict_types=1);

/**
 * Phase 3 slice 3a — the fuel register and the trip's fuel panel, driven through the real stack.
 *
 * `verify-fuel.php` proves the Actions and the schema. This sweep proves the two screens that carry
 * them: that a slip, a station, a tank and an issue are readable to the seats §9.3 names, that a
 * seat without `fuel.record` is offered no control that would refuse it, that the trip panel is the
 * only place an issue is recorded, and that a rendered page still shows exactly one filled action.
 *
 * Fixtures live in the `FUE` namespace on the development database. Everything created here is
 * removed afterwards and then asserted gone, and the job/trip counters are re-aligned so
 * `documents:number-integrity` sees only surviving rows.
 *
 * Run: php scripts/render-fuel.php   (set RENDER_FUEL_TRACE=1 for stack traces)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Actions\CreateFuelStation;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\Actions\RecordFuelIssue;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\RouteRateType;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
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
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class FuelRenderSweep extends TestCase
{
    public int $failures = 0;

    private int $companyId = 1;

    /** @var list<int> */
    private array $truckIds = [];

    /** @var list<string> */
    private array $jobIds = [];

    /** @var list<string> */
    private array $tripIds = [];

    /** @var list<string> */
    private array $issueIds = [];

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

        // Every Action below gates its own write (`fuel.record`, `expenses.create`), and a sweep that
        // builds fixtures with nobody signed in would be refused before it could test anything. The
        // per-seat checks still act as their own users through `actingAs()`.
        Auth::login($owner);

        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        $clerk = $this->staffAs($owner, RoleKey::StoresClerk, 'render-fuel-check-clerk@fleetguard.local');
        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-fuel-check-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-fuel-check-dispatcher@fleetguard.local');
        $reader = $this->staffAs($owner, null, 'render-fuel-check-reader@fleetguard.local');

        CurrentCompany::force($this->companyId);

        $base = CurrentCompany::model()->base_currency_code;

        /* Fixtures through the Actions, the way the screens write them. */

        $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'FUE Verify Customer', code: 'FUE-CUST', paymentTerms: 'net_30'));
        $route = (app(CreateRoute::class))(new RouteDto(
            code: 'FUE-GBE-FRW',
            origin: 'Gaborone',
            destination: 'Francistown',
            originCountryCode: 'BW',
            destinationCountryCode: 'BW',
            distanceKm: '430',
        ));

        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 950 FUE',
            fleetNo: 'T-FUE1',
            vin: 'VINFUE000000000000001',
            make: 'Probe',
            model: 'Fuel',
            odometerKm: 130_000,
            mainTankLitres: 400,
            auxTankLitres: 200,
        ));
        $this->truckIds[] = $truck->id;

        $mainTank = (app(CreateFuelTank::class))($truck->id, FuelTankKind::Main);

        $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-FUE001', name: 'FUE Verify Driver', hiredOn: '2024-02-01'));
        $driver = (app(CreateDriver::class))(new DriverDto(
            employeeId: $employee->id,
            licenceNo: 'DL-FUE001',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
            allowanceRateBase: 35_000,
            kioskEnabled: true,
        ));

        $job = (app(CreateJob::class))(new JobDto(
            customerId: $customer->id,
            routeId: $route->id,
            commodity: 'cement',
            tonnes: 30.0,
            requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
            requiredDeliveryOn: Carbon::now()->addDays(4)->toDateString(),
            notes: 'FUE render job',
        ));
        $this->jobIds[] = $job->id;

        $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '900.00', currencyCode: $base), 1);
        $job = (app(AcceptJob::class))($job, 1);

        $trip = (app(CreateTrip::class))($job, 1);
        $trip->forceFill(['truck_id' => $truck->id, 'driver_id' => $driver->id])->save();
        $this->tripIds[] = $trip->id;
        $trip = $trip->fresh();

        $station = (app(CreateFuelStation::class))('FUE Engen Gaborone', 'Gaborone');
        $slip = (app(CreateFuelPurchase::class))(new FuelPurchaseDto(
            amount: Money::ofMinor(740_000, $base),
            rate: '1',
            rateSource: FxRateSource::Manual,
            rateDate: Carbon::now(),
            purchasedOn: Carbon::now(),
            litresX100: 40_000,
            pricePerLitreMinor: 1850,
            stationId: $station->id,
            invoiceNo: 'FUE-SLIP-1',
        ), 1);

        $issue = (app(RecordFuelIssue::class))($trip, new FuelIssueDto(
            tankId: $mainTank->id,
            litresX100: 25_050,
            odometerKm: 130_400,
            occurredOn: Carbon::now(),
            purchaseId: $slip->id,
        ), 1);
        $this->issueIds[] = $issue->id;

        echo "== The fuel register renders, and a slip's remaining litres are on it ==\n";

        $this->attempt('the owner opens /fuel and sees the slip, the station, the issue and 149.50 L left', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/fuel')->assertOk()->getContent();

            foreach (['FUE-SLIP-1', 'FUE Engen Gaborone', '149.50', 'TRP-', '250.50', 'Main tank'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the fuel register HTML is missing {$needle}");
                }
            }
        });

        $this->attempt('that page shows exactly one filled action (§5)', function () use ($owner): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/fuel')->assertOk()->getContent();
            $this->assertOneFilledAction($html, '/fuel');
        });

        $this->attempt('the accountant reads fuel and is offered no recording control', function () use ($accountant): void {
            $html = (string) $this->actingAs($accountant, 'web')->get('/fuel')->assertOk()->getContent();

            if (! str_contains($html, 'FUE-SLIP-1')) {
                throw new Exception('the accountant cannot see the register (§9.3 gives fuel.view)');
            }

            foreach (['open-fuel-slip', 'open-fuel-station', 'open-fuel-tank', 'Record a slip'] as $control) {
                if (str_contains($html, $control)) {
                    throw new Exception("the register offered a recording control ({$control}) to a seat without fuel.record");
                }
            }
        });

        $this->attempt('the dispatcher sees the register but not the recording dialogs', function () use ($dispatcher): void {
            $html = (string) $this->actingAs($dispatcher, 'web')->get('/fuel')->assertOk()->getContent();

            if (! str_contains($html, 'FUE-SLIP-1') || str_contains($html, 'open-fuel-slip')) {
                throw new Exception('the dispatcher either lost the register or was offered a write it does not hold');
            }
        });

        $this->attempt('a seat with no fuel permission is refused the route', function () use ($reader): void {
            $status = $this->actingAs($reader, 'web')->get('/fuel')->getStatusCode();

            if ($status !== 403) {
                throw new Exception("expected 403, got {$status}");
            }

            echo "  ok    (unpermissioned user answered {$status})\n";
        });

        echo "\n== The trip's fuel panel is where fuel is issued (§10.9) ==\n";

        $this->attempt('the trip page carries the fuel panel with the issue it already has', function () use ($owner, $trip): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/trips/'.$trip->id)->assertOk()->getContent();

            foreach (['Main tank', '250.50', 'Record fuel'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the trip page is missing {$needle} from its fuel panel");
                }
            }
        });

        $this->attempt('the trip page shows exactly one filled action (§5)', function () use ($owner, $trip): void {
            $html = (string) $this->actingAs($owner, 'web')->get('/trips/'.$trip->id)->assertOk()->getContent();
            $this->assertOneFilledAction($html, '/trips/'.$trip->id);
        });

        $this->attempt('an accountant opening the trip sees fuel and no way to issue it', function () use ($accountant, $trip): void {
            $html = (string) $this->actingAs($accountant, 'web')->get('/trips/'.$trip->id)->assertOk()->getContent();

            if (! str_contains($html, '250.50') || str_contains($html, 'open-trip-fuel')) {
                throw new Exception('the accountant either lost the fuel panel or was offered the issue dialog');
            }
        });

        echo "\n== The register's three dialogs write what they say (§4.3) ==\n";

        $this->attempt('a station registered through the dialog lands once and refuses its duplicate', function () use ($clerk): void {
            Livewire::actingAs($clerk)
                ->test('pages::fuel.index')
                ->set('stationName', 'FUE Puma Francistown')
                ->set('stationTown', 'Francistown')
                ->call('addStation')
                ->assertHasNoErrors('station')
                ->assertDispatched('close-fuel-station');

            if (DB::table('fuel_stations')->where('name', 'FUE Puma Francistown')->count() !== 1) {
                throw new Exception('the station dialog did not write one row');
            }

            Livewire::actingAs($clerk)
                ->test('pages::fuel.index')
                ->set('stationName', 'FUE Puma Francistown')
                ->call('addStation')
                ->assertHasErrors('station');
        });

        $this->attempt('a tank fitted through the dialog takes the truck\'s stated capacity', function () use ($clerk, $truck): void {
            Livewire::actingAs($clerk)
                ->test('pages::fuel.index')
                ->set('tankTruckId', (string) $truck->id)
                ->set('tankKind', 'auxiliary')
                ->call('fitTank')
                ->assertHasNoErrors('tank')
                ->assertDispatched('close-fuel-tank');

            $capacity = DB::table('fuel_tanks')->where('truck_id', $truck->id)->where('kind', 'auxiliary')->value('capacity_litres');

            if ((int) $capacity !== 200) {
                throw new Exception('the auxiliary tank did not take the truck\'s stated 200 L, got '.(string) $capacity);
            }
        });

        $this->attempt('a slip recorded through the dialog posts its arithmetic, not a typed amount', function () use ($clerk): void {
            Livewire::actingAs($clerk)
                ->test('pages::fuel.index')
                ->set('slipLitres', '100.00')
                ->set('slipPrice', '18.50')
                ->set('slipInvoice', 'FUE-SLIP-2')
                ->call('recordSlip')
                ->assertHasNoErrors('slip')
                ->assertDispatched('close-fuel-slip');

            $slip = FuelPurchase::query()->where('invoice_no', 'FUE-SLIP-2')->first();

            if ($slip === null || $slip->amount !== 185_000 || $slip->litres !== 10_000) {
                throw new Exception('the slip dialog wrote the wrong figures');
            }
        });

        $this->attempt('a typed litre figure that is not a number is refused beside the form', function () use ($clerk): void {
            Livewire::actingAs($clerk)
                ->test('pages::fuel.index')
                ->set('slipLitres', 'four hundred')
                ->set('slipPrice', '18.50')
                ->call('recordSlip')
                ->assertHasErrors('slip');
        });

        echo "\n== The trip panel issues fuel through RecordFuelIssue, and refuses what it must ==\n";

        $this->attempt('the clerk issues 50 L from the second slip and the trip cost is posted', function () use ($clerk, $trip): void {
            $second = FuelPurchase::query()->where('invoice_no', 'FUE-SLIP-2')->firstOrFail();
            $tankId = (int) DB::table('fuel_tanks')->where('truck_id', $trip->truck_id)->where('kind', 'main')->value('id');

            Livewire::actingAs($clerk)
                ->test('pages::fuel.trip-panel', ['trip' => $trip])
                ->set('tankId', (string) $tankId)
                ->set('purchaseId', $second->id)
                ->set('litres', '50')
                ->set('odometer', '130_900')
                ->set('occurredOn', Carbon::now()->toDateString())
                ->call('record')
                ->assertHasErrors('odometer');

            Livewire::actingAs($clerk)
                ->test('pages::fuel.trip-panel', ['trip' => $trip])
                ->set('tankId', (string) $tankId)
                ->set('purchaseId', $second->id)
                ->set('litres', '50')
                ->set('odometer', '130900')
                ->set('occurredOn', Carbon::now()->toDateString())
                ->call('record')
                ->assertHasNoErrors('fuel')
                ->assertDispatched('close-trip-fuel')
                ->assertDispatched('trip-fuel-recorded');

            /** @var FuelIssue $latest */
            $latest = FuelIssue::query()->where('trip_id', $trip->id)->where('litres_x100', 5_000)->firstOrFail();
            $this->issueIds[] = $latest->id;

            if ($latest->cost_base !== 92_500 || $latest->status->value !== 'posted' || $latest->trip_cost_id === null) {
                throw new Exception('the panel did not post a 50 L issue at BWP 18.50');
            }
        });

        $this->attempt('an issue larger than the slip has left is refused by name', function () use ($clerk, $trip): void {
            $slip = FuelPurchase::query()->where('invoice_no', 'FUE-SLIP-1')->firstOrFail();
            $tankId = (int) DB::table('fuel_tanks')->where('truck_id', $trip->truck_id)->where('kind', 'main')->value('id');

            $refused = Livewire::actingAs($clerk)
                ->test('pages::fuel.trip-panel', ['trip' => $trip])
                ->set('tankId', (string) $tankId)
                ->set('purchaseId', $slip->id)
                ->set('litres', '400')
                ->set('odometer', '131000')
                ->set('occurredOn', Carbon::now()->toDateString())
                ->call('record')
                ->assertHasErrors('fuel');

            $message = (string) $refused->errors()->first('fuel');

            // The sentence has to carry the figure, or the clerk cannot tell a typo from a short slip.
            if (! str_contains($message, '149.50')) {
                throw new Exception('the refusal does not say how much the slip has left: '.$message);
            }
        });

        $this->attempt('draining a slip takes it out of the panel\'s choices but not its history', function () use ($clerk, $trip): void {
            $slip = FuelPurchase::query()->where('invoice_no', 'FUE-SLIP-1')->firstOrFail();
            $tankId = (int) DB::table('fuel_tanks')->where('truck_id', $trip->truck_id)->where('kind', 'main')->value('id');

            Livewire::actingAs($clerk)
                ->test('pages::fuel.trip-panel', ['trip' => $trip])
                ->set('tankId', (string) $tankId)
                ->set('purchaseId', $slip->id)
                ->set('litres', '149.50')
                ->set('odometer', '131000')
                ->set('occurredOn', Carbon::now()->toDateString())
                ->call('record')
                ->assertHasNoErrors('fuel')
                ->assertDispatched('trip-fuel-recorded');

            /** @var FuelIssue $drained */
            $drained = FuelIssue::query()->where('trip_id', $trip->id)->where('litres_x100', 14_950)->firstOrFail();
            $this->issueIds[] = $drained->id;

            $html = (string) Livewire::actingAs($clerk)->test('pages::fuel.trip-panel', ['trip' => $trip])->html();

            if (str_contains($html, 'value="'.$slip->id.'"')) {
                throw new Exception('an exhausted slip is still offered as a choice');
            }

            if (! str_contains($html, 'FUE-SLIP-1')) {
                throw new Exception('draining the slip also erased the issues that named it');
            }
        });

        $this->attempt('a frozen trip offers no issue dialog at all', function () use ($owner, $driver): void {
            $frozen = (app(CreateTrip::class))(Job::query()->findOrFail($this->jobIds[0]), 1);
            $frozen->forceFill([
                'status' => 'closed',
                'costs_frozen' => true,
                'truck_id' => $this->truckIds[0],
                'driver_id' => $driver->id,
            ])->save();
            $this->tripIds[] = $frozen->id;

            $html = (string) Livewire::actingAs($owner)
                ->test('pages::fuel.trip-panel', ['trip' => $frozen->fresh()])
                ->html();

            if (str_contains($html, 'open-trip-fuel')) {
                throw new Exception('a closed, frozen trip still offers the issue dialog');
            }
        });
    }

    private function staffAs(User $owner, ?RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render FUE Check '.($role?->label() ?? 'No Role');
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

            if (getenv('RENDER_FUEL_TRACE') !== false) {
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

        $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'FUE%')->pluck('id')->all();
        $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();
        $truckIds = DB::table('trucks')->where('registration_no', 'like', '%FUE')->pluck('id')->all();
        $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-FUE%')->pluck('id')->all();
        $driverIds = $employeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $employeeIds)->pluck('id')->all();
        $issueIds = $tripIds === []
            ? []
            : DB::table('fuel_issues')->whereIn('trip_id', $tripIds)->pluck('id')->all();
        $costIds = $tripIds === []
            ? []
            : DB::table('trip_costs')->whereIn('trip_id', $tripIds)->pluck('id')->all();

        $costKeys = array_map(static fn (int|string $id): string => (string) $id, $costIds);
        $entryIds = $costKeys === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'trip_cost')
            ->whereIn('source_id', $costKeys)
            ->pluck('id')
            ->all();

        if ($entryIds !== []) {
            LedgerBarrier::withLiftedGuard('render-fuel: remove this sweep\'s trip-cost entries', null, function () use ($entryIds): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            });
        }

        $this->deleteIn('fuel_issues', 'id', $issueIds);
        DB::table('fuel_purchases')->where('invoice_no', 'like', 'FUE-%')->delete();
        $this->deleteIn('fuel_tanks', 'truck_id', $truckIds);
        $this->deleteIn('trip_costs', 'trip_id', $tripIds);
        $this->deleteIn('vehicle_assignments', 'trip_id', $tripIds);
        $this->deleteIn('loads', 'trip_id', $tripIds);
        $this->deleteIn('odometer_readings', 'trip_id', $tripIds);
        $this->deleteIn('odometer_readings', 'truck_id', $truckIds);
        $this->deleteIn('operational_events', 'aggregate_id', array_values(array_unique(array_merge($tripIds, $issueIds))));
        $this->deleteIn('operational_events', 'aggregate_id', $jobIds);
        $this->deleteIn('trips', 'id', $tripIds);
        $this->deleteIn('freight_jobs', 'id', $jobIds);
        $this->deleteIn('drivers', 'id', $driverIds);
        $this->deleteIn('employees', 'id', $employeeIds);
        $this->deleteIn('trucks', 'id', $truckIds);
        DB::table('fuel_stations')->where('name', 'like', 'FUE %')->delete();
        DB::table('routes')->where('code', 'like', 'FUE%')->delete();
        DB::table('customers')->where('code', 'like', 'FUE%')->delete();

        $probeIds = DB::table('users')->where('email', 'like', 'render-fuel-check-%@fleetguard.local')->pluck('id')->all();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
        // A seat that wrote the trail, and the counter that drew this run's numbers, both name the
        // seat with a real foreign key. Let go before the account can.
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

        foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
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
            'fuel issues' => (int) DB::table('fuel_issues')->whereIn('id', $this->issueIds ?: ['0'])->count(),
            'fuel purchases' => (int) DB::table('fuel_purchases')->where('invoice_no', 'like', 'FUE-%')->count(),
            'fuel tanks' => (int) DB::table('fuel_tanks')->whereIn('truck_id', $this->truckIds ?: [0])->count(),
            'fuel stations' => (int) DB::table('fuel_stations')->where('name', 'like', 'FUE %')->count(),
            'trips' => (int) DB::table('trips')->whereIn('id', $this->tripIds ?: ['0'])->count(),
            'jobs' => (int) DB::table('freight_jobs')->whereIn('id', $this->jobIds ?: ['0'])->count(),
            'trucks' => (int) DB::table('trucks')->whereIn('id', $this->truckIds ?: [0])->count(),
            'probe seats' => (int) DB::table('users')->where('email', 'like', 'render-fuel-check-%@fleetguard.local')->count(),
        ];

        foreach ($leftovers as $what => $count) {
            echo $count === 0 ? "  ok    no {$what} survive\n" : "  FAIL  {$count} {$what} survive\n";

            if ($count !== 0) {
                $this->failures++;
            }
        }

        // The run drew job and trip numbers; with its rows gone, a counter still pointing past the
        // last surviving number reads as drift. Align first, then ask the command.
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

$sweep = new FuelRenderSweep('sweep');

try {
    $sweep->drive();
} catch (Throwable $crashed) {
    echo "\n  FAIL  the sweep stopped — ".$crashed::class.': '.$crashed->getMessage()."\n";
    echo $crashed->getTraceAsString()."\n";

    $sweep->failures++;
}

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
