<?php

declare(strict_types=1);

/**
 * Phase 1's master-data screens, rendered and driven through the real HTTP/Livewire stack.
 *
 * `verify-master.php` proves the domain and the schema; this file proves the part no gate can see
 * (the three defects were all invisible to `Gate::forUser()` and `Route::has()`): the component
 * compiles, the masked value is what the HTML prints, the dispatcher's field gates blank the
 * amounts, and a refused assignment surfaces as an error beside the button rather than a 500.
 *
 * Not a test suite: tests/ stays out of the repository. It writes to the development
 * database inside the `RD-` / `EMP-RD*` fixture namespace and removes everything it created.
 *
 * Run: php scripts/render-master.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Settings\CompanySettings;
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

        // The third band the register can answer, placed against the company's own warning window
        // rather than a number this file likes: a fixture that is only "expiring soon" while the knob
        // sits at its default proves the seed, not the screen.
        $window = max(1, (int) app(CompanySettings::class)->get('documents.expiry_warning_days'));
        $soonDays = min(14, $window);

        $windowEmployee = (app(CreateEmployee::class))(new EmployeeDto(
            employeeNo: 'EMP-RD003',
            name: 'Render Window Driver',
            hiredOn: '2025-01-15',
        ));
        $this->employeeIds[] = $windowEmployee->id;

        // One row for the two remaining paper questions at once: a licence inside the window, and a
        // permit with no date on file at all — the state the `missing` band exists to find.
        (app(CreateDriver::class))(new DriverDto(
            employeeId: $windowEmployee->id,
            licenceNo: 'DL-RD0003',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addDays($soonDays)->toDateString(),
            allowanceRateBase: 30_000,
        ));

        echo "\nThe registers render for the roles the design says may see them\n";
        $this->pageContains($owner, '/fleet', 'B 988 RD', 'the fleet register lists the fixture truck');
        $this->pageContains($dispatcher, '/fleet', 'B 988 RD', 'a dispatcher may open the fleet register');
        $this->pageContains($owner, "/fleet/trucks/{$truck->id}", 'B 988 RD', 'the vehicle detail page renders its timeline');
        $this->pageContains($owner, '/people', 'Render Fixture Driver', 'the people register lists the fixture driver');
        $this->pageContains($clerk, '/parts', 'Filters', 'the stores clerk may open the parts register');

        echo "\n what the HTML prints is never the secret\n";
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

        echo "\n the drivers tab reads the papers the way dispatch and the alert ladder read them\n";

        $this->attempt('a lapsed licence prints its state, and the row takes the worse of its two papers', function () use ($owner): void {
            $row = $this->rowFor($this->driversHtml($owner, ''), 'Render Lapsed Driver');

            if ($row === null) {
                throw new Exception('the lapsed driver is missing from the unfiltered drivers page');
            }

            if (! str_contains($row, 'border-l-2 border-danger')) {
                throw new Exception('the row whose licence lapsed did not take the danger edge');
            }

            if (! str_contains($row, 'Expired') || ! str_contains($row, 'days ago')) {
                throw new Exception('the lapsed licence printed no state word or no distance: '.$row);
            }
        });

        $this->attempt('a paper inside the company’s window prints the warning band with its days, and a date nobody recorded says so', function () use ($owner, $soonDays): void {
            $row = $this->rowFor($this->driversHtml($owner, ''), 'Render Window Driver');

            if ($row === null) {
                throw new Exception('the window driver is missing from the unfiltered drivers page');
            }

            if (! str_contains($row, 'border-l-2 border-warning')) {
                throw new Exception('the row inside the window did not take the warning edge: '.$row);
            }

            if (! str_contains($row, 'Expiring soon') || ! str_contains($row, 'in '.$soonDays.' days')) {
                throw new Exception('the window printed neither its state nor its distance: '.$row);
            }

            if (! str_contains($row, 'none on file')) {
                throw new Exception('the missing permit rendered as if it had a date');
            }
        });

        $this->attempt('a current paper prints the date alone — no badge, because a window nobody is inside has nothing to say', function () use ($owner): void {
            $row = $this->rowFor($this->driversHtml($owner, ''), 'Render Fixture Driver');

            if ($row === null) {
                throw new Exception('the current driver is missing from the unfiltered drivers page');
            }

            if (str_contains($row, 'Expiring soon') || str_contains($row, 'Expired')) {
                throw new Exception('a six-month paper was printed as a problem — check the company’s warning window');
            }

            if (! str_contains($row, 'border-l-2 border-transparent')) {
                throw new Exception('a cleared driver’s row still carries a coloured edge');
            }
        });

        $this->attempt('the Papers filter returns the band it names, and counts the rows it will leave', function () use ($owner): void {
            // The option labels are read from the unfiltered page and the totals from the filtered one, so
            // this is the screen's own claim — "Expiring soon (3)" meaning three rows appear — tested as
            // markup rather than as a method call nobody renders.
            $base = $this->driversHtml($owner, '');

            $bands = [
                'soon' => ['label' => 'Expiring soon', 'present' => 'Render Window Driver', 'absent' => 'Render Fixture Driver'],
                'expired' => ['label' => 'Already lapsed', 'present' => 'Render Lapsed Driver', 'absent' => 'Render Fixture Driver'],
                'missing' => ['label' => 'No date on file', 'present' => 'Render Window Driver', 'absent' => 'Render Fixture Driver'],
            ];

            foreach ($bands as $band => $expect) {
                if (preg_match('/'.preg_quote($expect['label'], '/').' \((\d+)\)/', $base, $counted) !== 1) {
                    throw new Exception('the '.$expect['label'].' option printed no count');
                }

                $html = $this->driversHtml($owner, $band);

                if (! $this->hasRow($html, $expect['present'])) {
                    throw new Exception($band.' lost '.$expect['present']);
                }

                if ($this->hasRow($html, $expect['absent'])) {
                    throw new Exception($band.' kept '.$expect['absent'].' — a driver with two current papers is in no problem band');
                }

                if (preg_match('/of (\d+) drivers\./', $html, $total) !== 1) {
                    throw new Exception('the filtered page printed no total for '.$band);
                }

                if ((int) $counted[1] !== (int) $total[1]) {
                    throw new Exception($expect['label'].' promises '.$counted[1].' and returns '.$total[1]);
                }
            }
        });

        echo "\n the field gates blank what the role may not read\n";
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

        $this->attempt('the readings table says how far the truck went between measurements', function () use ($owner, $truck): void {
            // Written through the door rather than the form above: the column is what this checks, and a
            // broken form somewhere else in the run must not be able to make it look broken.
            $record = app(RecordOdometerReading::class);

            foreach ([41_250, 41_900] as $index => $km) {
                $record(new OdometerReadingDto(
                    truckId: (int) $truck->id,
                    odometerKm: $km,
                    readOn: Carbon::now()->subDays(10 - $index)->toDateString(),
                ));
            }

            $html = Livewire::actingAs($owner)
                ->test('pages::fleet.vehicle', ['truck' => $truck])
                ->html();

            if (! str_contains($html, 'Travelled')) {
                throw new Exception('the readings table has no Travelled column');
            }

            // 41,900 less the 41,250 before it — the leg, not the total from the truck's first number.
            if (! str_contains($html, '650 km')) {
                throw new Exception('the reading after 41,250 km did not report 650 km travelled');
            }
        });

        $this->attempt('the assignment card puts the band on the option and the reason beside the name', function () use ($owner, $truck, $lapsedDriver): void {
            $option = $this->driverOption($this->assignmentCard($owner, $truck), $lapsedDriver->id);

            if (! str_contains($option, 'text-danger')) {
                throw new Exception('a lapsed licence did not put danger on the option: '.$option);
            }

            if (! str_contains($option, 'licence Expired')) {
                throw new Exception('the option carries a colour and no reason: '.$option);
            }

            // The same driver read against a day three months on: the permit that covers today has lapsed
            // by then. The card answers for the handover day, which is why the tone is derived here at all.
            $later = $this->driverOption($this->assignmentCard($owner, $truck, Carbon::now('Africa/Gaborone')->addDays(90)->format('Y-m-d\TH:i')), $lapsedDriver->id);

            if (! str_contains($later, 'PRDP permit Expired')) {
                throw new Exception('the permit that lapses before that day was not named on the option: '.$later);
            }
        });

        $this->attempt('the chosen driver\'s papers stay on the page after the list closes', function () use ($owner, $truck, $lapsedDriver, $validDriver): void {
            $picked = $this->assignmentCard($owner, $truck, '', (string) $lapsedDriver->id);

            if (! str_contains($picked, 'role="status"') || ! str_contains($picked, 'days ago')) {
                throw new Exception('choosing a lapsed driver printed no standing line under the select'
                    .(str_contains($picked, 'id="assignment-driver"') ? '' : ' — the card printed no driver select at all'));
            }

            if (! str_contains($picked, 'bg-danger-soft')) {
                throw new Exception('the standing line printed no danger badge');
            }

            // Silence is the other half of the feature: a driver whose papers cover the day is not a
            // problem, and a green badge on every row is what makes the two tones unreadable.
            $clean = $this->assignmentCard($owner, $truck, '', (string) $validDriver->id);

            if (str_contains($clean, 'role="status"')) {
                throw new Exception('a driver whose papers cover the handover day was still printed as a problem');
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

        echo "\n the document register links a paper to a subject\n";
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

    /**
     * The drivers table's rows, as markup.
     *
     * The body and nothing else: the employee select in the editor under the table also prints names, so
     * a check that read the whole document could be answered by a form field instead of by a row.
     */
    private function driverRows(string $html): string
    {
        $start = strpos($html, 'wire:key="driver-');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, '</tbody>', $start);

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    /** Whether the rendered drivers table has a row for this driver at all. */
    private function hasRow(string $html, string $name): bool
    {
        return str_contains($this->driverRows($html), $name);
    }

    /** The row that names this driver, or null when the rendered table has no row for them. */
    private function rowFor(string $html, string $name): ?string
    {
        $rows = $this->driverRows($html);
        $at = strpos($rows, $name);

        if ($at === false) {
            return null;
        }

        $start = strrpos(substr($rows, 0, $at), '<tr');
        $end = strpos($rows, '</tr>', $at);

        if ($start === false || $end === false) {
            throw new Exception('the rendered rows are not separable at '.$name);
        }

        return substr($rows, $start, $end - $start);
    }

    /** The drivers tab as rendered, with the Papers filter set to this band — '' for the whole register. */
    private function driversHtml(User $user, string $band): string
    {
        return (string) Livewire::actingAs($user)
            ->test('pages::people.index')
            ->set('tab', 'drivers')
            ->set('papersFilter', $band)
            ->html();
    }

    /** The assignment card as rendered, with the handover day and the chosen driver set. */
    private function assignmentCard(User $user, Truck $truck, string $fromAt = '', string $driverId = ''): string
    {
        $card = Livewire::actingAs($user)->test('pages::fleet.vehicle', ['truck' => $truck]);

        if ($fromAt !== '') {
            $card->set('fromAt', $fromAt);
        }

        if ($driverId !== '') {
            $card->set('driverId', $driverId);
        }

        return (string) $card->html();
    }

    /** The markup one driver's option carries — name, dates, tone and reason between the open and the close. */
    private function driverOption(string $html, int $driverId): string
    {
        $start = strpos($html, 'value="'.$driverId.'" class=');

        if ($start === false) {
            throw new Exception('the driver select printed no option for driver '.$driverId
                .(str_contains($html, 'id="assignment-driver"') ? ' — the select rendered, the option did not' : ' — the assignment card printed no driver select at all'));
        }

        $end = strpos($html, '</option>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
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
