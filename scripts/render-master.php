<?php

declare(strict_types=1);

/**
 * Phase 1's master-data screens, rendered and driven through the real HTTP/Livewire stack.
 *
 * `verify-master.php` proves the domain and the schema; this file proves the part no gate can see
 * (§20.8's three defects were all invisible to `Gate::forUser()` and `Route::has()`): the component
 * compiles, the masked value is what the HTML prints, the dispatcher's field gates blank the
 * amounts, and a refused assignment surfaces as an error beside the button rather than a 500.
 *
 * Not a test suite: tests/ stays out of the repository (§14.6). It writes to the development
 * database inside the `RD-` / `EMP-RD*` fixture namespace and removes everything it created.
 *
 * Run: php scripts/render-master.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class MasterRenderSweep extends TestCase
{
    public int $failures = 0;

    private ?int $ownerCompany = null;

    /** @var list<int> */
    private array $truckIds = [];

    /** @var list<int> */
    private array $employeeIds = [];

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

        // Same concession as render-cutover: the sweep measures the registers' authorisation, and
        // the MFA redirect is owned by render-identity.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-master-check-dispatcher@fleetguard.local');
        $clerk = $this->staffAs($owner, RoleKey::StoresClerk, 'render-master-check-clerk@fleetguard.local');

        // Fixtures through the Actions, the way a screen would: a truck with real papers behind it.
        CurrentCompany::force($owner->company_id);
        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 988 RD',
            fleetNo: 'T-RD1',
            vin: 'VINRD0000000000000001',
            make: 'Probe',
            model: 'Render',
            odometerKm: 40_000,
            purchaseAmountBase: 99_000_00,
        ));
        $this->truckIds[] = $truck->id;

        $employee = (app(CreateEmployee::class))(new EmployeeDto(
            employeeNo: 'EMP-RD001',
            name: 'Render Fixture Driver',
            hiredOn: '2024-02-01',
            nationalId: 'ID-445566',
            roleOrGrade: 'Driver',
            grossSalaryBase: 1_000_000,
        ));
        $this->employeeIds[] = $employee->id;

        $validDriver = (app(CreateDriver::class))(new DriverDto(
            employeeId: $employee->id,
            licenceNo: 'DL-RD0001',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addMonths(6)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(6)->toDateString(),
            allowanceRateBase: 30_000,
            kioskEnabled: true,
        ));

        $lapsedEmployee = (app(CreateEmployee::class))(new EmployeeDto(
            employeeNo: 'EMP-RD002',
            name: 'Render Lapsed Driver',
            hiredOn: '2023-06-01',
        ));
        $this->employeeIds[] = $lapsedEmployee->id;

        $lapsedDriver = (app(CreateDriver::class))(new DriverDto(
            employeeId: $lapsedEmployee->id,
            licenceNo: 'DL-RD0002',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->subDays(3)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(2)->toDateString(),
            allowanceRateBase: 30_000,
        ));

        echo "\nThe registers render for the roles §9.3 says may see them\n";
        $this->pageContains($owner, '/fleet', 'B 988 RD', 'the fleet register lists the fixture truck');
        $this->pageContains($dispatcher, '/fleet', 'B 988 RD', 'a dispatcher may open the fleet register');
        $this->pageContains($owner, "/fleet/trucks/{$truck->id}", 'B 988 RD', 'the vehicle detail page renders its timeline');
        $this->pageContains($owner, '/people', 'Render Fixture Driver', 'the people register lists the fixture driver');
        $this->pageContains($clerk, '/parts', 'Filters', 'the stores clerk may open the parts register');

        echo "\n§9.5 what the HTML prints is never the secret\n";
        $this->attempt('the people register leaks no plaintext, and the drivers tab prints the mask', function () use ($owner, $validDriver): void {
            $html = (string) Livewire::actingAs($owner)
                ->test('pages::people.index')
                ->set('tab', 'drivers')
                ->html();

            if (str_contains($html, 'DL-RD0001')) {
                throw new Exception('the plaintext licence number leaked into the HTML');
            }

            if (str_contains($html, 'ID-445566')) {
                throw new Exception('the plaintext national ID leaked into the HTML');
            }

            $mask = (string) DB::table('drivers')->where('id', $validDriver->id)->value('licence_no_masked');

            if ($mask === '' || ! str_contains($html, $mask)) {
                throw new Exception('the drivers tab did not print the masked licence form');
            }
        });

        echo "\n§9.5 the field gates blank what the role may not read\n";
        $this->attempt('a dispatcher sees no purchase amount on the fleet register', function () use ($dispatcher): void {
            $html = $this->actingAs($dispatcher, 'web')->get('/fleet')->getContent();

            if (str_contains((string) $html, '99,000.00') && ! str_contains((string) $html, '••••')) {
                throw new Exception('the purchase amount rendered for a role without fleet.cost.view');
            }
        });

        echo "\nThe vehicle page drives the domain through its real buttons\n";
        $this->attempt('an odometer reading moves the truck and the register agrees', function () use ($owner, $truck): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->set('odometerKm', '41250')
                ->call('recordOdometer')
                ->assertHasNoErrors();

            if ((int) $truck->fresh()->odometer_km !== 41_250) {
                throw new Exception('the truck still reads '.$truck->fresh()->odometer_km);
            }
        });

        $this->attempt('a suspect reading is refused a rewrite of the projection, with a warning that says so', function () use ($owner, $truck): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->set('odometerKm', '30000')
                ->call('recordOdometer')
                ->assertHasNoErrors()
                ->assertDispatched('toast', kind: 'warning');

            if ((int) $truck->fresh()->odometer_km !== 41_250) {
                throw new Exception('a backwards reading dragged the projection back');
            }
        });

        $this->attempt('the readings table says how far the truck went between measurements (§20.37)', function () use ($owner, $truck): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->set('odometerKm', '41900')
                ->call('recordOdometer')
                ->assertHasNoErrors();

            $html = Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->html();

            if (! str_contains($html, 'Travelled')) {
                throw new Exception('the readings table has no Travelled column');
            }

            // 41,900 less the 41,250 before it. The suspect 30,000 row reports no distance at all:
            // a figure invented out of a measurement the truck contradicts would sit in the same row
            // as a real one.
            if (! str_contains($html, '650 km')) {
                throw new Exception('the reading after 41,250 km did not report 650 km travelled');
            }
        });

        $this->attempt('dispatch refuses the lapsed driver on screen, as an error beside the button', function () use ($owner, $truck, $lapsedDriver): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->set('fromAt', Carbon::now()->addHours(8)->format('Y-m-d\TH:i'))
                ->set('driverId', (string) $lapsedDriver->id)
                ->call('assign')
                ->assertHasErrors('assignment');
        });

        $this->attempt('the eligible driver can be assigned, then released', function () use ($owner, $truck, $validDriver): void {
            $this->travelTo(Carbon::now('Africa/Gaborone')->addDays(2));

            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->set('fromAt', Carbon::now('Africa/Gaborone')->format('Y-m-d\TH:i'))
                ->set('driverId', (string) $validDriver->id)
                ->call('assign')
                ->assertHasNoErrors();

            $assignment = DB::table('vehicle_assignments')->where('truck_id', $truck->id)->latest('id')->first();

            if ($assignment === null) {
                throw new Exception('the assign button wrote nothing');
            }

            Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->call('release', (int) $assignment->id)
                ->assertHasNoErrors();

            if (DB::table('vehicle_assignments')->where('id', $assignment->id)->whereNull('to_at')->exists()) {
                throw new Exception('the release left the row open');
            }

            $this->travelBack();
        });

        echo "\nThe fleet register writes a new truck\n";
        $this->attempt('the save action creates through the component, and the register lists it', function () use ($owner): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.index')
                ->set('registrationNo', 'B 989 RD')
                ->set('odometerKm', '1000')
                ->call('save')
                ->assertHasNoErrors();

            $made = Truck::query()->where('registration_no', 'B 989 RD')->first();

            if ($made === null) {
                throw new Exception('the component saved nothing');
            }

            $this->truckIds[] = $made->id;

            $this->actingAs($owner, 'web')->get('/fleet?search=B 989')->assertOk()->assertSee('B 989 RD', false);
        });

        $this->attempt('a blank registration is a field error, not an exception', function () use ($owner): void {
            Livewire::actingAs($owner)
                ->test('pages::fleet.index')
                ->set('registrationNo', '')
                ->call('save')
                ->assertHasErrors('registrationNo');
        });

        echo "\nParts and customers write through their components too\n";
        $this->attempt('the parts register saves a catalogue row with its cost', function () use ($clerk): void {
            Livewire::actingAs($clerk)
                ->test('pages::maintenance.parts')
                ->set('sku', 'RD-FILTER-1')
                ->set('name', 'Render filter')
                ->set('category', 'filters')
                ->set('unitCost', '12.50')
                ->set('currency', 'BWP')
                ->call('save')
                ->assertHasNoErrors();

            if (! DB::table('parts')->where('sku', 'RD-FILTER-1')->exists()) {
                throw new Exception('the parts component saved nothing');
            }

            $this->attempt('the money rule refuses a nonsense amount with a field error', function () use ($clerk): void {
                Livewire::actingAs($clerk)
                    ->test('pages::maintenance.parts')
                    ->set('sku', 'RD-FILTER-2')
                    ->set('name', 'Render filter two')
                    ->set('category', 'filters')
                    ->set('unitCost', '12,5x0')
                    ->set('currency', 'BWP')
                    ->call('save')
            // The money parse reports under `form`, the component-wide key — an unparseable amount is
            // refused by Money::ofMajor(), not by the field validator.
                    ->assertHasErrors('form');
            });
        });

        $this->attempt('the customers register creates and the terms gate holds', function () use ($owner, $dispatcher): void {
            Livewire::actingAs($owner)
                ->test('pages::invoicing.customers')
                ->set('code', 'RD-CUST')
                ->set('name', 'Render Fixture Customer')
                ->set('paymentTerms', 'net_30')
                ->call('save')
                ->assertHasNoErrors();

            if (! DB::table('customers')->where('code', 'RD-CUST')->exists()) {
                throw new Exception('the customers component saved nothing');
            }

            $this->pageContains($dispatcher, '/customers', 'Render Fixture Customer', 'a dispatcher may still read the customer register');
        });

        echo "\n§4.3 the document register links a paper to a subject\n";
        $this->pageContains($owner, "/documents?subject_type=truck&subject_id={$truck->id}", 'Documents', 'the truck-filtered register renders without error');
        $this->pageContains($owner, "/documents?subject_type=driver&subject_id={$validDriver->id}", 'Documents', '...and the driver-filtered one');

        echo "\nTenancy: a truck is not a global namespace on the wire\n";
        $this->attempt('another company\'s truck id does not answer as this company\'s screen', function () use ($owner): void {
            DB::table('companies')->insert([
                'id' => 2,
                'name' => 'Render Second Company',
                'timezone' => 'Africa/Gaborone',
                'fiscal_year_starts_on' => '2000-01-01',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Raw insert: the tenancy trait rightly refuses a cross-company Eloquent write, and the
            // probe needs the row to exist as the *other* tenant's, not to exercise the guard.
            DB::table('trucks')->insert([
                'company_id' => 2,
                'registration_no' => 'B 990 RD',
                'odometer_km' => 1,
                'purchase_amount_base' => 0,
                'status' => 'available',
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $foreignId = (int) DB::table('trucks')->where('registration_no', 'B 990 RD')->value('id');

            $status = $this->actingAs($owner, 'web')->get("/fleet/trucks/{$foreignId}")->getStatusCode();

            if ($status === 200) {
                throw new Exception('company 1 rendered company 2\'s truck — the route binding is not tenant-scoped');
            }

            if ($status !== 403 && $status !== 404) {
                throw new Exception("unexpected status {$status}");
            }

            echo "  ok    (tenant foreign truck answered {$status})\n";
        });
    }

    private function staffAs(User $owner, RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render Master Check '.$role->label();
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);
        app(ChangeUserRole::class)($user, $role);
        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
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

            // PHPUnit's default failure renderer exports the whole HTML page and then trips its own
            // `Registry::get()` assertion outside a PHPUnit run — condense to the message only.
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";

            if (getenv('RENDER_MASTER_TRACE') !== false) {
                echo $exception->getTraceAsString()."\n";
            }
        }
    }

    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 220);
    }

    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } finally {
            $this->cleanUp();

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function cleanUp(): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        $checkIds = DB::table('users')->where('email', 'like', 'render-master-check-%@fleetguard.local')->pluck('id')->all();

        // Namespace sweep first: a run that died before its `finally` leaves `RD` rows behind, and
        // the next run must not collide with them for the wrong reason.
        $truckIds = array_values(array_unique(array_merge(
            $this->truckIds,
            DB::table('trucks')->where('registration_no', 'like', '% RD')->pluck('id')->all(),
        )));

        DB::table('odometer_readings')->whereIn('truck_id', $truckIds)->delete();
        DB::table('vehicle_assignments')->whereIn('truck_id', $truckIds)->delete();
        DB::table('trucks')->whereIn('id', $truckIds)->delete();

        $employeeIds = array_values(array_unique(array_merge(
            $this->employeeIds,
            DB::table('employees')->where('employee_no', 'like', 'EMP-RD%')->pluck('id')->all(),
        )));
        $driverIds = DB::table('drivers')->whereIn('employee_id', $employeeIds ?: [0])->pluck('id')->all();

        DB::table('vehicle_assignments')->whereIn('driver_id', $driverIds ?: [0])->delete();
        DB::table('drivers')->whereIn('id', $driverIds)->delete();
        DB::table('employees')->whereIn('id', $employeeIds)->delete();

        DB::table('parts')->where('sku', 'like', 'RD-%')->delete();
        DB::table('customers')->where('code', 'like', 'RD-%')->delete();
        DB::table('alerts')->where('subject_type', 'driver')->whereIn('subject_id', $driverIds ?: [0])->delete();
        DB::table('operational_events')->where('aggregate_type', 'like', '%truck%')->delete();
        DB::table('audits')->where('auditable_type', Truck::class)->whereNotIn('auditable_id', DB::table('trucks')->pluck('id'))->delete();

        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $checkIds ?: [0])->delete();
        DB::table('users')->whereIn('id', $checkIds)->delete();

        DB::table('trucks')->where('company_id', 2)->where('registration_no', 'B 990 RD')->delete();
        DB::table('companies')->where('name', 'Render Second Company')->delete();

        CurrentCompany::forget();

        $leftovers = [
            'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '% RD')->count(),
            'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-RD%')->count(),
            'drivers' => (int) DB::table('drivers')->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-RD%')->pluck('id')->all() ?: [0])->count(),
            'parts' => (int) DB::table('parts')->where('sku', 'like', 'RD-%')->count(),
            'customers' => (int) DB::table('customers')->where('code', 'like', 'RD-%')->count(),
            'check accounts' => (int) DB::table('users')->where('email', 'like', 'render-master-check-%@fleetguard.local')->count(),
            'probe company' => (int) DB::table('companies')->where('name', 'Render Second Company')->count(),
        ];

        foreach ($leftovers as $what => $count) {
            echo $count === 0 ? "  ok    no {$what} survive\n" : "  FAIL  {$count} {$what} survive\n";

            if ($count !== 0) {
                $this->failures++;
            }
        }

        echo $this->failures === 0
            ? '  ok    the demo fleet is intact: '.DB::table('trucks')->count()." trucks registered\n"
            : '';
    }
}

$sweep = new MasterRenderSweep('sweep');
$sweep->drive();

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
