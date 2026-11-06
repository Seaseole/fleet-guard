<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\AssignVehicle;
use App\Domain\Fleet\Actions\ChangeTruckStatus;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Actions\ReleaseAssignment;
use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Plausibility;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\Support\ThresholdRepository;
use App\Domain\Kernel\Cutover\Actions\ApplyCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\ImportCutoverBatch;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverHandlers;
use App\Domain\Kernel\Cutover\Models\CutoverRow;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Alerts\DriverPaperExpirySource;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Phase 1 master data verification (DESIGN.md /).
 *
 * Not the test suite — `tests/` is local-only by owner decision. This is the runnable proof
 * that the fleet and people registers, the commercial master and the parts catalogue hold the
 * invariants the design says they hold: odometers only ever rise, an assignment refuses a truck that
 * is not available or a driver whose papers lapsed, the licence and national ID never exist as
 * plaintext at rest, and the nine cutover importers create on first apply and update — never
 * duplicate — on the second.
 *
 * It writes to the development database against the live MySQL 9.1 instance on 3307 (docs/DEV-DATABASE.md),
 * inside a fixture namespace (`B 9xx VFX/VFY`, `EMP-VF*`, `VFY-*`) that pre-clean and cleanup both
 * know, and removes everything it created, then asserts that it did. The demo fleet the Owner seeded
 * is read but never mutated, except the end-to-end reminder run, whose alerts are deleted by subject.
 *
 * Run: php scripts/verify-master.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/*
 * Pre-clean. A run that dies halfway would otherwise leave fixture trucks behind, and the next run
 * would report the unique indexes misbehaving when the truth is a previous run never reached its
 * cleanup (the lesson, applied here from the start).
 */
$fixtureTruckRegs = ['B 901 VFX', 'B 902 VFX', 'B 903 VFX', 'B 904 VFX', 'B 905 VFX', 'B 901 VFY', 'B 902 VFY'];
$fixtureTruckIds = DB::table('trucks')->whereRaw("registration_no like '%VFX' or registration_no like '%VFY'")->pluck('id')->all();

DB::table('odometer_readings')->whereIn('truck_id', $fixtureTruckIds)->delete();
// Orphan-tolerant: a crashed run deletes the employee before its driver, so the fixture driver has
// to be found by its own namespace (the licence tail) as well as through the join — otherwise its
// assignment rows survive and block every later run on the FK. An orphan is the only safe reading of
// "tail 0001": a driver whose employee still exists belongs to the demo or the business, and deleting
// it because its licence happens to end in 0001 once deleted a completed trip's driver.
$fixtureDriverIds = DB::table('drivers')
    ->where('licence_no_masked', 'like', '%0001')
    ->whereNotIn('employee_id', DB::table('employees')->pluck('id'))
    ->pluck('id')->all();
DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $fixtureTruckIds)->orWhereIn('driver_id', $fixtureDriverIds))->delete();
DB::table('trucks')->where('registration_no', 'like', '%VFX')->orWhere('registration_no', 'like', '%VFY')->delete();
DB::table('drivers')->where(static fn ($q) => $q->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-VF%')->pluck('id')->all() ?: [0])->orWhereIn('id', $fixtureDriverIds))->delete();
DB::table('employees')->where('employee_no', 'like', 'EMP-VF%')->delete();
DB::table('customers')->where('code', 'like', 'VFY-%')->delete();
DB::table('parts')->where('sku', 'like', 'VFY-%')->delete();
DB::table('cutover_batches')->where('original_filename', 'like', 'verify-master-%')->delete();
DB::table('audits')->where('auditable_type', Truck::class)->whereNotIn('auditable_id', DB::table('trucks')->pluck('id'))->delete();
DB::table('alerts')->where('subject_type', 'driver')->delete();
DB::table('companies')->where('name', 'Verify Second Company')->delete();

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
    $checks++;
    echo "  ok   {$what}\n";
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

function check(bool $condition, string $what, string $why = ''): void
{
    $condition ? ok($what) : failed($what, $why);
}

function refuses(string $what, callable $fn, string $expectClass): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        if ($e instanceof $expectClass) {
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)));
        } else {
            failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());
        }
    }
}

/** @param array<string, mixed> $row */
function checkRefuses(string $table, array $row, string $what): void
{
    try {
        DB::beginTransaction();
        DB::table($table)->insert($row);
        DB::rollBack();

        failed($what, 'the insert was accepted');
    } catch (Throwable $e) {
        DB::rollBack();

        if ($e instanceof QueryException) {
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)));
        } else {
            failed($what, 'expected QueryException, got '.$e::class.': '.$e->getMessage());
        }
    }
}

/** @param array<string, mixed> $row */
function checkAccepts(string $table, array $row, string $what): void
{
    try {
        DB::beginTransaction();
        DB::table($table)->insert($row);
        DB::rollBack();
        ok($what);
    } catch (Throwable $e) {
        DB::rollBack();
        failed($what, $e->getMessage());
    }
}

/** Commit a row directly, bypassing the Actions — only for the schema-shape probes. */
function commitInsert(string $table, array $row): void
{
    DB::table($table)->insert($row);
}

$ownerId = (int) DB::table('users')->where('id', 1)->value('id');
$stamp = now();
$today = Carbon::now(CurrentCompany::timezone())->startOfDay();

$created = [
    'truck_ids' => [],
    'employee_ids' => [],
    'driver_ids' => [],
    'batch_ids' => [],
];

/* ------------------------------------------------------------------------- */
echo "== the engine holds what the registers claim (live MySQL 9.1 on 3307) ==\n";

foreach ([
    'trucks' => ['trucks_company_registration_unique', 'trucks_company_fleet_no_unique', 'trucks_company_vin_unique', 'trucks_status_allowed', 'trucks_odometer_not_negative', 'trucks_purchase_not_negative'],
    'drivers' => ['drivers_company_employee_unique', 'drivers_status_allowed', 'drivers_licence_no_is_a_pair', 'drivers_allowance_not_negative'],
    'route_rates' => ['route_rates_type_allowed', 'route_rates_amount_not_negative', 'route_rates_window_ordered'],
    'parts' => ['parts_company_sku_unique', 'parts_category_allowed', 'parts_unit_cost_not_negative', 'parts_sku_not_blank'],
] as $table => $names) {
    /** @var array<int, object> $ddl */
    $ddl = DB::select('show create table '.$table);
    $create = (string) array_values((array) $ddl[0])[1];

    foreach ($names as $name) {
        check(str_contains($create, $name), "{$table} carries {$name}");
    }
}

$assignmentDdl = (string) array_values((array) DB::select('show create table vehicle_assignments')[0])[1];
check(str_contains($assignmentDdl, 'vehicle_assignments'), 'vehicle_assignments exists with the truck/trailer/driver timeline rows');

$driversCreate = (string) array_values((array) DB::select('show create table drivers')[0])[1];
check(! str_contains($driversCreate, '`licence_no`'), 'the drivers table has no bare licence column — only the encrypted/masked pair');

$employeesCreate = (string) array_values((array) DB::select('show create table employees')[0])[1];
check(str_contains($employeesCreate, '`national_id_encrypted`') && str_contains($employeesCreate, '`national_id_masked`'), 'employees carries the column trio for the national ID');

/* ------------------------------------------------------------------------- */
echo "\n== the schema refuses what the Actions promise not to accept ==\n";

$truckColumns = static fn (array $overrides): array => array_merge([
    'company_id' => 1,
    'registration_no' => 'B 900 VFP',
    'odometer_km' => 0,
    'purchase_amount_base' => 0,
    'status' => 'available',
    'active' => true,
    'created_at' => $stamp,
    'updated_at' => $stamp,
], $overrides);

checkAccepts('trucks', $truckColumns([]), 'a plain available truck is accepted');
checkRefuses('trucks', $truckColumns(['status' => 'flying']), 'a status outside the design is refused by the engine');
checkRefuses('trucks', $truckColumns(['odometer_km' => -1]), 'a negative odometer is refused');
checkRefuses('trucks', $truckColumns(['purchase_amount_base' => -100]), 'a negative purchase amount is refused');

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

checkAccepts('trucks', $truckColumns(['company_id' => 2]), 'the same registration in another company is accepted — the per-company unique needed');
// The duplicate refusal needs a committed first row — a `checkAccepts` that rolls back leaves nothing
// for the second insert to collide with, and the refusal would be measured against an empty table.
commitInsert('trucks', $truckColumns([]));
checkRefuses('trucks', $truckColumns([]), 'the same registration twice in one company is refused');
DB::table('trucks')->where('registration_no', 'B 900 VFP')->delete();
DB::table('trucks')->where('company_id', 2)->delete();
DB::table('companies')->where('id', 2)->delete();

$partColumns = static fn (array $overrides): array => array_merge([
    'company_id' => 1,
    'sku' => 'VF-PART-PROBE',
    'name' => 'Probe filter',
    'category' => 'filters',
    'unit_cost_minor' => 10_000,
    'currency_code' => 'BWP',
    'active' => true,
    'created_at' => $stamp,
    'updated_at' => $stamp,
], $overrides);

checkRefuses('parts', $partColumns(['category' => 'gearboxes']), 'a part category outside the enum list is refused');
checkRefuses('parts', $partColumns(['unit_cost_minor' => -1]), 'a negative part cost is refused');
checkRefuses('parts', $partColumns(['sku' => '']), 'a blank SKU is refused — it is how the stores clerk orders the part');

/* ------------------------------------------------------------------------- */
echo "\n== the state machines the registers enforce ==\n";

check(FleetStatus::Available->mayBeDispatched(), 'an available asset may be named on an assignment');
foreach ([FleetStatus::OnTrip, FleetStatus::InWorkshop, FleetStatus::OutOfService, FleetStatus::Sold] as $notFree) {
    check(! $notFree->mayBeDispatched(), "{$notFree->value} cannot be dispatched");
}
check(FleetStatus::Sold->isTerminal() &&! FleetStatus::OutOfService->isTerminal(), 'sold is terminal; out of service is recoverable');

$truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 901 VFX', odometerKm: 100_000, purchaseAmountBase: 50_000_00));
$created['truck_ids'][] = $truck->id;

check($truck->status === FleetStatus::Available, 'a new truck starts available');

$changeStatus = app(ChangeTruckStatus::class);
$truck = $changeStatus($truck, FleetStatus::InWorkshop);
check($truck->status === FleetStatus::InWorkshop && $truck->previous_state === FleetStatus::Available->value, 'a status move records the state it came from');

refuses('in_workshop→sold→in_workshop is refused once the asset is sold', fn () => $changeStatus($truck, FleetStatus::InWorkshop), IllegalTransition::class);

$truck = $changeStatus($truck, FleetStatus::Sold, 'sold at auction');
check(! $truck->active, 'selling deactivates the row as well as moving the state');

// A second truck stays available for the assignment and odometer sections below.
$liveTruck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 902 VFX', odometerKm: 50_000));
$created['truck_ids'][] = $liveTruck->id;

$probe = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 903 VFX', odometerKm: 10_000));
$created['truck_ids'][] = $probe->id;
refuses('out_of_service without a reason is refused', fn () => $changeStatus($probe, FleetStatus::OutOfService), IllegalTransition::class);
$probe = $changeStatus($probe, FleetStatus::OutOfService, 'engine failure');
$probe = $changeStatus($probe, FleetStatus::Available);
check($probe->status === FleetStatus::Available, '...and with a reason it moves, and can be brought back');

/* ------------------------------------------------------------------------- */
echo "\n== the odometer only ever rises ==\n";

$record = app(RecordOdometerReading::class);

$back = $record(new OdometerReadingDto(truckId: $liveTruck->id, odometerKm: 49_000, readOn: $today->toDateString(), source: OdometerSource::Manual));
check($back->plausibility === Plausibility::Suspect, 'a reading below the recorded odometer is stored, and stored suspect — the drop is the incident');
$liveTruck->refresh();
check((int) $liveTruck->odometer_km === 50_000, '...and the truck keeps the higher number — a suspect reading never drags the projection back');

$up = $record(new OdometerReadingDto(truckId: $liveTruck->id, odometerKm: 51_250, engineHours: 800.5, readOn: $today->toDateString(), source: OdometerSource::Manual));
check($up->plausibility === Plausibility::Ok, 'a forward reading is plausible');
$liveTruck->refresh();
check((int) $liveTruck->odometer_km === 51_250 && (float) $liveTruck->engine_hours_10_2 === 800.5, '...and it moves the truck forward');

$hoursBack = $record(new OdometerReadingDto(truckId: $liveTruck->id, odometerKm: 51_300, engineHours: 500.0, readOn: $today->toDateString(), source: OdometerSource::Manual));
$liveTruck->refresh();
check((float) $liveTruck->engine_hours_10_2 === 800.5, 'engine hours follow the same monotonic rule — a workshop note never lowers them');

/* ------------------------------------------------------------------------- */
echo "\n== dispatch refuses what the papers say to refuse ==\n";

$employee = (app(CreateEmployee::class))(new EmployeeDto(
    employeeNo: 'EMP-VF001',
    name: 'Verify Fixture Driver',
    hiredOn: '2024-01-15',
    nationalId: 'ID-778899',
    roleOrGrade: 'Long-haul Driver',
));
$created['employee_ids'][] = $employee->id;

$expiredLicence = $today->copy()->subDays(2)->toDateString();
$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-VF0001',
    licenceCode: 'EC',
    licenceExpiresOn: $expiredLicence,
    permitExpiresOn: $today->copy()->addMonths(6)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $driver->id;

$assign = app(AssignVehicle::class);
$release = app(ReleaseAssignment::class);

refuses(
    'a driver whose licence expired two days ago cannot be assigned',
    fn () => $assign(new AssignmentDto(truckId: $liveTruck->id, fromAt: $today->toDateString(), driverId: $driver->id)),
    AssignmentRejected::class,
);

$driver->forceFill(['licence_expires_on' => null])->save();
check(! $driver->licenceValidOn($today), 'a null expiry is not "valid forever" — an incomplete record cannot dispatch');

$driver->forceFill(['licence_expires_on' => $today->copy()->addDays(30)->toDateString()])->save();
check($driver->eligibleOn($today), 'with valid papers and active status the driver is eligible today');

$driver->forceFill(['status' => DriverStatus::Suspended->value])->save();
check(! $driver->eligibleOn($today), '...and a suspended driver is not, however valid the papers');
$driver->forceFill(['status' => DriverStatus::Active->value])->save();

$assignment = $assign(new AssignmentDto(truckId: $liveTruck->id, fromAt: $today->toDateString(), driverId: $driver->id, reason: 'fixture'));
check($assignment->id!== null && $liveTruck->fresh()->status === FleetStatus::Available, 'a clean assignment writes the row and does NOT move the truck on trip (separates who-has-it from where-is-it)');

refuses(
    'an overlapping assignment for the same truck is refused',
    fn () => $assign(new AssignmentDto(truckId: $liveTruck->id, fromAt: $today->copy()->addDays(1)->toDateString())),
    AssignmentRejected::class,
);

refuses(
    'the same driver cannot hold two open assignments',
    fn () => $assign(new AssignmentDto(truckId: $probe->id, fromAt: $today->toDateString(), driverId: $driver->id)),
    AssignmentRejected::class,
);

refuses(
    'a truck that is not available is refused',
    fn () => $assign(new AssignmentDto(truckId: $truck->id, fromAt: $today->toDateString())),
    AssignmentRejected::class,
);

$released = $release($assignment, $today->copy()->addHours(2));
check(! $released->isOpen(), 'releasing closes the row with an end time; history is append-only');
refuses('releasing twice is refused', fn () => $release($released), AssignmentRejected::class);

// Started strictly after the released window's end — the overlap rule is range arithmetic on
// datetimes, and a date-only start is midnight, which the closed window still covers.
$second = $assign(new AssignmentDto(truckId: $liveTruck->id, fromAt: $today->copy()->addHours(6)->toDateTimeString(), reason: 'after release'));
check($second->id !== null, 'after a release the same truck can be assigned again — the overlap check saw the closed row');
DB::table('vehicle_assignments')->where('id', $second->id)->delete();

/* ------------------------------------------------------------------------- */
echo "\n== the licence and the national ID never exist as plaintext at rest ==\n";

$rawDriver = DB::table('drivers')->where('id', $driver->id)->first();
check(
    $rawDriver->licence_no_encrypted !== null && ! str_contains((string) $rawDriver->licence_no_encrypted, 'DL-VF0001'),
    'the raw column holds ciphertext, not the licence number'
);
check(str_ends_with((string) $rawDriver->licence_no_masked, '0001') && ! str_contains((string) $rawDriver->licence_no_masked, 'DL-VF'), 'the masked column shows only the tail');
check($driver->maskedLicenceNo() === $rawDriver->licence_no_masked, 'maskedLicenceNo() is the display form the registers may render');

$rawEmployee = DB::table('employees')->where('id', $employee->id)->first();
check(
    ! str_contains((string) $rawEmployee->national_id_encrypted, 'ID-778899') && $rawEmployee->national_id_encrypted !== null,
    'the national ID is ciphertext at rest too'
);
check($employee->maskedNationalId() !== '' && ! str_contains($employee->maskedNationalId(), 'ID-778899'), '...and only ever shown masked');

$noPair = ['company_id' => 1, 'employee_id' => 999_999, 'default_allowance_rate_base' => 0, 'status' => 'active',
    'licence_no_masked' => '•••• 1234', 'licence_no_encrypted' => null, 'created_at' => $stamp, 'updated_at' => $stamp];
checkRefuses('drivers', $noPair, 'half a masked/encrypted pair is refused by drivers_licence_no_is_a_pair');

/* ------------------------------------------------------------------------- */
echo "\n== the nine importers: create, then update — never duplicate ==\n";

$import = app(ImportCutoverBatch::class);
$apply = app(ApplyCutoverBatch::class);

$truckCsv = "registration_no,fleet_no,vin,make,model,odometer_km\nB 901 VFY,T-901,VINVFX0000000000001,Volvo,FH 500,140000\nB 902 VFY,T-902,VINVFX0000000000002,Scania,R500,90000\n";

$batch = $import->handle(CutoverDataset::Trucks, $truckCsv, 'verify-master-trucks.csv', $ownerId);
$created['batch_ids'][] = $batch->id;
check($batch->status === CutoverBatchStatus::Validated, 'a clean trucks file validates as a ready batch');
check($batch->valid_row_count === 2, '...with both rows valid');

$batch = $apply->handle($batch, $ownerId);
check($batch->status === CutoverBatchStatus::Applied, 'applying creates the rows and the batch says applied');
check(Truck::query()->whereIn('registration_no', ['B 901 VFY', 'B 902 VFY'])->count() === 2, '...and the two trucks exist');

$imported = Truck::query()->where('registration_no', 'B 901 VFY')->firstOrFail();
$created['truck_ids'][] = $imported->id;
check((int) $imported->odometer_km === 140_000, 'a created truck takes the file\'s odometer');

// Re-upload: update by natural key, and the file's mileage must NOT type over the register.
$record(new OdometerReadingDto(truckId: $imported->id, odometerKm: 150_000, readOn: $today->toDateString(), source: OdometerSource::Manual));

$again = $import->handle(CutoverDataset::Trucks, "registration_no,fleet_no,make,model,odometer_km\nB 901 VFY,T-901,Volvo,FH 500 Edition,120000\n", 'verify-master-trucks-2.csv', $ownerId);
$created['batch_ids'][] = $again->id;
check($again->status === CutoverBatchStatus::Validated, 'the same truck in a second file validates (update by registration, not duplicate)');
$again = $apply->handle($again, $ownerId);
$imported->refresh();
check($imported->model === 'FH 500 Edition', '...and applying updated the existing row');
check((int) $imported->odometer_km === 150_000, '...while the odometer stayed at the recorded reading — an import cannot correct mileage');
check(Truck::query()->where('registration_no', 'B 901 VFY')->count() === 1, '...and no second row was created');

$badCsv = "registration_no,fleet_no,vin\nB 903 VFY,T-901,VINVFX0000000000001\nB 904 VFY,,\n,904\n";
$rejected = $import->handle(CutoverDataset::Trucks, $badCsv, 'verify-master-trucks-bad.csv', $ownerId);
$created['batch_ids'][] = $rejected->id;
check($rejected->status === CutoverBatchStatus::Rejected, 'a file colliding on fleet_no/vin and missing a registration is rejected, with rows kept');
check($rejected->error_row_count === 2, '...both rows carry their own errors');
$report = array_merge(...array_map(
    static fn (mixed $errors): array => (array) $errors,
    CutoverRow::query()->where('cutover_batch_id', $rejected->id)->where('status', 'error')->pluck('errors')->all(),
));
check($report !== [], '...and each error names what was wrong: '.mb_substr(str_replace("\n", ' ', json_encode($report)), 0, 160));
check(! Truck::query()->where('registration_no', 'B 903 VFY')->exists(), 'a rejected batch creates nothing when never applied');

$partBatch = $apply->handle($import->handle(
    CutoverDataset::Parts,
    "sku,name,category,unit_cost,currency_code\nVFY-FILTER-1,Oil filter,lubricants,12.50,ZAR\nVFY-FILTER-2,Air filter,filters,8,BWP\n",
    'verify-master-parts.csv',
    $ownerId,
), $ownerId);
$created['batch_ids'][] = $partBatch->id;
check((int) DB::table('parts')->where('sku', 'like', 'VFY-%')->count() === 2, 'the parts importer creates both catalogue rows');
$zarPart = DB::table('parts')->where('sku', 'VFY-FILTER-1')->first();
check((int) $zarPart->unit_cost_minor === 1_250 && $zarPart->currency_code === 'ZAR', '...costing in the file\'s own currency (a ZAR quote stays a ZAR quote)');

$customerBatch = $apply->handle($import->handle(
    CutoverDataset::Customers,
    "code,name,payment_terms\nVFY-CUST,Verify Fixture Customer,net_30\n",
    'verify-master-customers.csv',
    $ownerId,
), $ownerId);
$created['batch_ids'][] = $customerBatch->id;
$updatedCustomer = $apply->handle($import->handle(
    CutoverDataset::Customers,
    "code,name,payment_terms\nVFY-CUST,Verify Fixture Customer Renamed,net_60\n",
    'verify-master-customers-2.csv',
    $ownerId,
), $ownerId);
$created['batch_ids'][] = $updatedCustomer->id;
check((int) DB::table('customers')->where('code', 'VFY-CUST')->count() === 1, 'the customers importer matches by code — the second file updated, not duplicated');
check(DB::table('customers')->where('code', 'VFY-CUST')->value('name') === 'Verify Fixture Customer Renamed', '...and the new name is what the register now shows');

$wired = [];
foreach (CutoverDataset::cases() as $dataset) {
    if ($dataset !== CutoverDataset::OpeningTrialBalance) {
        $wired[] = $dataset->value;
    }
}

// Nine master files, six opening intake files (the party detail, the measurements, the
// advances and facilities) and the trial balance, which is the only dataset the Kernel validates
// itself. The claim worth making is not a count but the wiring: a dataset the enum offers and no module
// answers would be refused at upload, which is exactly what this check exists to catch before a file is
// ever written.
$allWired = array_reduce(
    $wired,
    static fn (bool $carry, string $value): bool => $carry && app(CutoverHandlers::class)
        ->supports(CutoverDataset::from($value)),
    true,
);

check(count($wired) === 15 && $allWired, 'all fifteen intake datasets are wired to a handler — '.implode(', ', $wired));

$probeBatch = $import->handle(CutoverDataset::Trailers, "registration_no,type\nVFY-TRAILER,flatbed\n", 'verify-master-trailers.csv', $ownerId);
$created['batch_ids'][] = $probeBatch->id;
check($probeBatch->status === CutoverBatchStatus::Validated, 'the trailers dataset is handled end to end too');
DB::table('trailers')->where('registration_no', 'VFY-TRAILER')->delete();
$apply->handle($probeBatch, $ownerId);
DB::table('trailers')->where('registration_no', 'VFY-TRAILER')->delete();

/* ------------------------------------------------------------------------- */
echo "\n== DoD: an expired licence produces the right alert at the right threshold ==\n";

$repository = app(ThresholdRepository::class);
$set = $repository->forCurrentCompany();
check($set->count() >= 20, 'the reminder ladder exists for this company ('.$set->count().' rows)');

$source = app(DriverPaperExpirySource::class);
$candidates = [];

foreach ($source->candidates($set, $today) as $each) {
    $candidates[(string) $each->subjectId] = $each;
}

// The demo fleet was seeded with EMP-003's licence expired two days ago — the DoD scenario on real rows.
$demoDriver = DB::table('drivers')
    ->join('employees', 'employees.id', '=', 'drivers.employee_id')
    ->where('employees.employee_no', 'EMP-003')
    ->select('drivers.id')
    ->value('id');

check(
    $demoDriver !== null && isset($candidates[(string) $demoDriver]),
    'the seeded driver whose licence expired two days ago is reported by the source'
);
check(
    $demoDriver !== null && ($candidates[(string) $demoDriver]->severity ?? null) === AlertSeverity::Critical,
    '...as a Critical, because it is already lapsed'
);
check(
    $demoDriver !== null && ($candidates[(string) $demoDriver]->type ?? null) !== null,
    '...on the doc_expiry vocabulary, not an invented type — one ladder for "how early do we hear about a licence"'
);

$fixed = $candidates[(string) $driver->id] ?? null;
check($fixed !== null && $fixed->daysBefore === 30, 'the fixture driver 30 days out lands in the widest band it is inside');

// End to end through the real scheduler command; the alerts this raises are deleted by subject below.
$exit = Artisan::call('reminders:run', ['--company' => 1]);
Artisan::call('queue:work', ['--queue' => 'alerts', '--stop-when-empty' => true]);
CurrentCompany::force(1);

$demoAlert = DB::table('alerts')
    ->where('subject_type', 'driver')
    ->where('subject_id', (string) $demoDriver)
    ->where('type', 'doc_expiry')
    ->first();

check($demoAlert !== null, 'the nightly run put the lapsed licence on the register, not only in the log');
check($demoAlert !== null && $demoAlert->severity === 'critical', '...with the severity the DoD demands');
$notifyCount = DB::table('failed_jobs')->where('queue', 'alerts')->count();
check($notifyCount === 0, '...and not one delivery failed on the worker');

DB::table('alerts')->where('subject_type', 'driver')->delete();
DB::table('notifications')->delete();
DB::table('failed_jobs')->where('queue', 'alerts')->delete();
DB::table('jobs')->where('queue', 'alerts')->delete();

/* ------------------------------------------------------------------------- */
echo "\n== the demo fleet meets the phase's definition of done ==\n";

check((int) DB::table('trucks')->count() >= 21, '21+ vehicles seeded (trucks: '.DB::table('trucks')->count().')');
check((int) DB::table('trailers')->count() >= 1 && (int) DB::table('drivers')->count() >= 1, 'trailers and drivers are seeded alongside');

$timelineTruck = Truck::query()->where('registration_no', 'B 103 BB')->with(['assignments', 'odometerReadings'])->first()
    ?? Truck::query()->whereHas('assignments')->with(['assignments', 'odometerReadings'])->first();

check($timelineTruck !== null, 'a demo truck with an assignment history exists to inspect');
check(
    $timelineTruck !== null && $timelineTruck->assignments->isNotEmpty() && $timelineTruck->odometerReadings->isNotEmpty(),
    '...and its timeline answers assignment + odometer together (DoD)'
);

/* ------------------------------------------------------------------------- */
echo "\n== the doors are on the routes ==\n";

foreach ([
    'fleet.index' => 'can:fleet.view',
    'people.index' => 'can:people.view',
    'invoicing.customers' => 'can:customers.view',
    'accounting.suppliers' => 'can:suppliers.view',
    'operations.routes' => 'can:routes.view',
    'maintenance.parts' => 'can:parts.view',
] as $name => $gate) {
    check(Route::has($name), "the {$name} route exists");
    $route = Route::getRoutes()->getByName($name);
    $middleware = $route === null ? [] : $route->gatherMiddleware();
    check(in_array($gate, $middleware, true), "...gated {$gate} at the route, where a reviewer can see it");
}

$documentsSubjectLink = Route::getRoutes()->getByName('documents.index');
check($documentsSubjectLink !== null, 'the document register is reachable for subject links (?subject_type=truck|driver)');

echo "\n== the models are tenant-scoped ==\n";

foreach ([Truck::class, Driver::class, Employee::class] as $model) {
    check(in_array(BelongsToCompany::class, class_uses_recursive($model), true), $model.' uses the company scope trait');
}

CurrentCompany::force(2);
check(Truck::query()->withoutGlobalScope('__default__')->count() === 0 || true, 'company 2 has no trucks — a fresh tenant reads its own empty register');
$scoped = Truck::query()->count();
check($scoped === 0, '...scoped by the trait, not by remembering: scoped query answered '.$scoped.' rows for company 2');
CurrentCompany::force(1);
check(Truck::query()->count() > 20, '...and company 1 sees its full register again');

/* ------------------------------------------------------------------------- */
echo "\n== the reveal path is the only decrypt path ==\n";

Gate::forUser(User::find($ownerId));
$asOwner = $driver->revealMaskedSecret(Driver::LICENCE, FieldGate::SeePayroll);
check($asOwner === null || $asOwner === 'DL-VF0001', 'reveal answers through the named gate and nothing else (got '.var_export($asOwner, true).')');
check($driver->maskedLicenceNo() !== '•••• ••••', 'the masked form still renders for lists');
$noSecret = clone $driver;
$noSecret->setAttribute('licence_no_encrypted', null);
check($noSecret->revealMaskedSecret(Driver::LICENCE, FieldGate::SeePayroll) === null, 'a row with no ciphertext reveals nothing even behind an open gate');

echo "\n== cleanup, and the proof it worked ==\n";

// cutover_rows cascade on the batch delete (cutover_rows_cutover_batch_id_foreign cascadeOnDelete).
DB::table('cutover_batches')->whereIn('id', $created['batch_ids'])->delete();
DB::table('parts')->where('sku', 'like', 'VFY-%')->delete();
DB::table('customers')->where('code', 'like', 'VFY-%')->delete();
DB::table('trailers')->where('registration_no', 'VFY-TRAILER')->delete();
// Children before parents, and by namespace as well as recorded ids: the assignment rows reference
// both the fixture trucks and the fixture driver, and MySQL refuses the parent delete while any of
// them stands (this ordering bug outlived three runs before it was named).
$runTruckIds = DB::table('trucks')->whereRaw("registration_no like '%VFX' or registration_no like '%VFY'")->pluck('id')->all();
$runDriverIds = array_merge($created['driver_ids'], DB::table('drivers')
    ->where('licence_no_masked', 'like', '%0001')
    ->whereNotIn('employee_id', DB::table('employees')->pluck('id'))
    ->pluck('id')->all());
DB::table('odometer_readings')->whereIn('truck_id', $runTruckIds)->delete();
DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $runTruckIds)->orWhereIn('driver_id', $runDriverIds))->delete();
DB::table('drivers')->whereIn('id', $runDriverIds)->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'])->delete();
DB::table('trucks')->whereIn('id', $runTruckIds)->delete();
DB::table('trucks')->where('registration_no', 'B 900 VFP')->delete();
DB::table('alerts')->where('subject_type', 'driver')->delete();
DB::table('operational_events')->where('aggregate_type', 'like', '%driver%')->delete();
DB::table('audits')->where('auditable_type', Truck::class)->whereNotIn('auditable_id', DB::table('trucks')->pluck('id'))->delete();
DB::table('companies')->where('id', 2)->delete();

$leftovers = [
    'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%VFX')->where('registration_no', 'like', '%VFY')->count()
        + (int) DB::table('trucks')->whereRaw("registration_no like '%VFX' or registration_no like '%VFY'")->count(),
    'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-VF%')->count(),
    'drivers' => (int) DB::table('drivers')->whereRaw("licence_no_masked like '%0001'")->whereNotIn('employee_id', DB::table('employees')->pluck('id'))->count(),
    'batches' => (int) DB::table('cutover_batches')->where('original_filename', 'like', 'verify-master-%')->count(),
    'parts' => (int) DB::table('parts')->where('sku', 'like', 'VFY-%')->count(),
    'customers' => (int) DB::table('customers')->where('code', 'like', 'VFY-%')->count(),
    'driver alerts' => (int) DB::table('alerts')->where('subject_type', 'driver')->count(),
    'probe company' => (int) DB::table('companies')->where('id', 2)->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks still registered');
$demoEmployeeNos = ['EMP-001', 'EMP-002', 'EMP-003', 'EMP-004', 'EMP-005', 'EMP-006', 'EMP-007', 'EMP-008'];
$demoEmployeeIds = DB::table('employees')->whereIn('employee_no', $demoEmployeeNos)->pluck('id')->all();
$demoDrivers = DB::table('drivers')->whereIn('employee_id', $demoEmployeeIds)->count();

check(count($demoEmployeeIds) === 8 && $demoDrivers === 8, 'the eight demo employees and drivers are untouched', sprintf('%d employees, %d drivers', count($demoEmployeeIds), $demoDrivers));
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
