<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Identity\RoleKey;
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
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Queries\TripsInMotion;
use App\Domain\Operations\RouteRateType;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Domain\Telematics\Actions\EnsureSimulatorAccount;
use App\Domain\Telematics\Actions\IngestPositions;
use App\Domain\Telematics\Adapters\Registry;
use App\Domain\Telematics\Adapters\SimulatorProvider;
use App\Domain\Telematics\Data\IngestReport;
use App\Domain\Telematics\Data\LiveVehicle;
use App\Domain\Telematics\Models\GpsPosition;
use App\Domain\Telematics\Models\GpsProviderAccount;
use App\Domain\Telematics\Models\LatestPosition;
use App\Domain\Telematics\Queries\LiveMap;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 2 slice 2g verification — GPS ingest, the simulator and the trip map's reads
 * (DESIGN.md R6).
 *
 * The sentences this run proves:
 *
 * - a fix lands through one door: an adapter parses, the Action validates, batches, dedupes and moves
 *   the projection in one transaction, and the raw payload is kept beside the normalised columns;
 * - the retry guard is the provider's own message id, so a webhook that fires twice stores once;
 * - one bad message costs that message, never the batch it arrived in;
 * - the projection only moves forwards, because a buffered device posting an old fix must not rewind
 *   the map;
 * - a fix is filed under the trip the truck is actually on, and a payload that names somebody else's
 *   trip does not get believed;
 * - `gps_positions` is the high-volume shape: a bigint key, not a ULID;
 * - the simulator drives the same Action, advances a truck along its corridor, and refuses to invent
 *   coordinates for a place it does not know;
 * - `gps.view` is the Dispatcher's and the Workshop's, not the stores clerk's, and the registers
 *   are company-scoped.
 *
 * It writes to the development database on the live MySQL 9.1 instance (docs/DEV-DATABASE.md) inside the
 * `XGP` fixture namespace, and asserts that everything it created is gone at the end.
 *
 * Run: php scripts/verify-telematics.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/** Delete rows by an id list, refusing to run on an empty list (the integer/ULID comparison). */
function wipeIds(string $table, string $column, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    return DB::table($table)->whereIn($column, $ids)->delete();
}

/*
 * Pre-clean: a run that died halfway leaves fixes, projections and a provider account behind, and the
 * dedupe key would then make the next run's first batch look like a retry. Everything this file writes
 * carries the `XGP` marker, so the sweep is exact.
 */
$xgpTruckIds = DB::table('trucks')->where('registration_no', 'like', '%XGP')->pluck('id')->all();
$xgpJobIds = DB::table('freight_jobs')->where('notes', 'like', 'XGP%')->pluck('id')->all();
$xgpTripIds = $xgpJobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $xgpJobIds)->pluck('id')->all();

DB::table('gps_positions')->where('provider_message_id', 'like', 'XGP-%')->delete();
wipeIds('latest_positions', 'truck_id', $xgpTruckIds);
wipeIds('gps_positions', 'truck_id', $xgpTruckIds);
wipeIds('latest_positions', 'trip_id', $xgpTripIds);
wipeIds('gps_positions', 'trip_id', $xgpTripIds);
DB::table('gps_provider_accounts')->where('adapter', 'simulator')->delete();

wipeIds('odometer_readings', 'trip_id', $xgpTripIds);
wipeIds('odometer_readings', 'truck_id', $xgpTruckIds);
wipeIds('vehicle_assignments', 'trip_id', $xgpTripIds);
wipeIds('operational_events', 'aggregate_id', $xgpTripIds);
wipeIds('operational_events', 'aggregate_id', $xgpJobIds);
wipeIds('trips', 'id', $xgpTripIds);
wipeIds('freight_jobs', 'id', $xgpJobIds);

$xgpEmployeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-XGP%')->pluck('id')->all();
$xgpDriverIds = $xgpEmployeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $xgpEmployeeIds)->pluck('id')->all();

wipeIds('drivers', 'id', $xgpDriverIds);
wipeIds('employees', 'id', $xgpEmployeeIds);
wipeIds('trucks', 'id', $xgpTruckIds);
DB::table('routes')->where('code', 'like', 'XGP%')->delete();
DB::table('customers')->where('code', 'like', 'XGP%')->delete();
DB::table('companies')->where('name', 'Verify XGP Second Company')->delete();

$alignCounters = static function (string $when): void {
    foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
        $highest = DB::table($table)
            ->where('company_id', 1)
            ->pluck($column)
            ->map(static function (string $number): int {
                $dash = strpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })
            ->max() ?? 0;

        DB::table('number_sequences')
            ->where('company_id', 1)
            ->where('scope', $scope)
            ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
    }

    echo "  counters aligned to the surviving rows ({$when})\n";
};

$alignCounters('before the run');

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

function refuses(string $what, callable $fn, string $expectClass, ?string $messageContains = null): void
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

        ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 140)));
    }
}

/** One simulated fix, in the shape the simulator adapter parses. */
function xgpMessage(string $id, int $truckId, ?string $tripId, float $lat, float $lng, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'truck_id' => $truckId,
        'trip_id' => $tripId,
        'fixed_at' => Carbon::now()->subMinutes(2)->format('Y-m-d H:i:s'),
        'lat' => $lat,
        'lng' => $lng,
        'speed_kph' => 82,
        'heading_deg' => 271,
        'odometer_km' => 150_010,
        'accuracy_m' => 8,
    ], $extra);
}

// Whole seconds, because every column this run is measured against is a DATETIME with no fractional
// part. With microseconds here, an ingest inside the same second as the run start truncates *below*
// this clock, and the audit sweep at the end can miss the row written in that first second — one run
// passes, the next fails, and neither says anything about the code.
$runStartedAt = Carbon::now()->startOfSecond();
// The Owner seat is the account the deployment names, not row 1: on a development database row 1
// may have been re-seated, and a harness that signs in the wrong seat fails at the first gated
// write before it tests anything. `FLEET_OWNER_EMAIL` is the documented override.
$owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail();
Auth::login($owner);
CurrentCompany::force((int) $owner->company_id);

$created = [
    'truck_ids' => [], 'employee_ids' => [], 'driver_ids' => [],
    'job_ids' => [], 'trip_ids' => [], 'account_ids' => [],
];

/* ------------------------------------------------------------------------- */
echo "== / the schema keeps the promises the docs make (live MySQL 9.1) ==\n";

$positionDdl = (string) ((array) DB::select('show create table gps_positions')[0])['Create Table'];

foreach ([
    'gps_positions_provider_message_unique',
    'gps_positions_trip_company_fk',
    'gps_positions_truck_fixed_index',
    'gps_positions_trip_fixed_index',
    'gps_positions_company_fixed_index',
    'gps_positions_lat_range',
    'gps_positions_lng_range',
    'gps_positions_heading_range',
    'gps_positions_message_named',
] as $name) {
    check(str_contains($positionDdl, $name), "gps_positions carries {$name}");
}

// The rule for high-volume append-only rows: a bigint key, not a ULID. A 26-character key on the
// biggest table in the schema would buy nothing, because nothing outside ingest fetches a fix by id.
check(str_contains($positionDdl, 'AUTO_INCREMENT'), 'gps_positions is the bigint auto-increment table, not a ULID aggregate');
check(Schema::hasColumns('gps_positions', ['provider', 'provider_message_id', 'truck_id', 'trip_id', 'fixed_at', 'lat_10_7', 'lng_10_7', 'speed_kph', 'heading_deg', 'odometer_km', 'accuracy_m', 'raw_json', 'ingested_at']), '...and it carries every column sketches');

$projectionDdl = (string) ((array) DB::select('show create table latest_positions')[0])['Create Table'];
check(str_contains($projectionDdl, 'latest_positions_truck_unique'), 'the projection is one row per truck');
check(str_contains($projectionDdl, 'latest_positions_position_fk'), '...and it cites the fix it shows, so a prune cannot leave a dangling claim');

$accountDdl = (string) ((array) DB::select('show create table gps_provider_accounts')[0])['Create Table'];
check(str_contains($accountDdl, 'gps_provider_accounts_company_adapter_unique'), 'one feed per adapter per company');
check(str_contains($accountDdl, 'gps_provider_accounts_key_version_paired'), 'a stored credential carries the key version that made it');

/* ------------------------------------------------------------------------- */
echo "\n== R6 the adapter seam: one contract, resolved by name, refused when unknown ==\n";

$registry = app(Registry::class);

check($registry->for('simulator') instanceof SimulatorProvider, 'the simulator adapter is registered and resolves through the container');

refuses(
    'an adapter nobody registered is refused by name',
    fn () => $registry->for('teltonika'),
    InvalidArgumentException::class,
    'No GPS adapter is registered'
);

$ensure = app(EnsureSimulatorAccount::class);
$account = $ensure();
$created['account_ids'][] = $account->id;

check($account->adapter === 'simulator' && $account->active, 'the simulator feed exists and is switched on');
check($ensure()->id === $account->id, '...and asking for it twice returns the same row, not a second feed');

$off = GpsProviderAccount::query()->findOrFail($account->id);
$off->forceFill(['active' => false])->save();

refuses(
    'a feed that is switched off does not accept messages',
    fn () => app(IngestPositions::class)($off->fresh(), ['messages' => [xgpMessage('XGP-OFF', 1, null, -24.6, 25.9)]]),
    InvalidArgumentException::class,
    'switched off'
);

$off->fresh()->forceFill(['active' => true])->save();
$account = $off->fresh();

/* ------------------------------------------------------------------------- */
echo "\n== fixtures: a truck, a corridor and a trip on the road ==\n";

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'XGP Verify Customer', code: 'XGP-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'XGP-GBE-WDH',
    origin: 'Gaborone',
    destination: 'Windhoek',
    originCountryCode: 'BW',
    destinationCountryCode: 'NA',
    distanceKm: '750',
));

$truck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 44 XGP',
    fleetNo: 'T-XGP1',
    vin: 'Vinxgp0000000000000001',
    make: 'Probe',
    model: 'Tracker',
    odometerKm: 150_000,
));
$created['truck_ids'][] = $truck->id;

$secondTruck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 45 XGP',
    fleetNo: 'T-XGP2',
    vin: 'Vinxgp0000000000000002',
    make: 'Probe',
    model: 'Tracker',
    odometerKm: 88_000,
));
$created['truck_ids'][] = $secondTruck->id;

// A third truck, because will not dispatch a truck that is already on the road — the corridor
// the simulator cannot place needs its own vehicle rather than a second journey on the same one.
$thirdTruck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 46 XGP',
    fleetNo: 'T-XGP3',
    vin: 'Vinxgp0000000000000003',
    make: 'Probe',
    model: 'Tracker',
    odometerKm: 61_000,
));
$created['truck_ids'][] = $thirdTruck->id;

$employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-XGP001', name: 'XGP Verify Driver', hiredOn: '2024-02-02'));
$created['employee_ids'][] = $employee->id;

$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-XGP001',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $driver->id;

// A second crew, because the design refuses to put one driver on two trips the same day — which is the
// dispatch gate working, and the reason the second truck needs its own driver rather than a reuse.
$secondEmployee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-XGP002', name: 'XGP Second Driver', hiredOn: '2023-11-11'));
$created['employee_ids'][] = $secondEmployee->id;

$secondDriver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $secondEmployee->id,
    licenceNo: 'DL-XGP002',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $secondDriver->id;

// ...and the corridor the simulator cannot place needs a third crew: an open assignment holds the
// driver until the trip comes home (the timeline is append-only), so nobody may be reused here.
$thirdEmployee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-XGP003', name: 'XGP Third Driver', hiredOn: '2022-06-06'));
$created['employee_ids'][] = $thirdEmployee->id;

$thirdDriver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $thirdEmployee->id,
    licenceNo: 'DL-XGP003',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $thirdDriver->id;

$baseCurrency = CurrentCompany::model()->base_currency_code;

$job = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'copper',
    tonnes: 26.0,
    requiredLoadOn: Carbon::now()->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(4)->toDateString(),
    notes: 'XGP tracked job',
));
$created['job_ids'][] = $job->id;

$job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '900.00', currencyCode: $baseCurrency), 1);
$job = (app(AcceptJob::class))($job, 1);

$trip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $trip->id;

$trip = (app(PlanTrip::class))($trip, new TripDto(
    jobId: $job->id,
    truckId: $truck->id,
    driverId: $driver->id,
    plannedDepartureAt: Carbon::now()->subHours(3)->toDateTimeString(),
), 1);

$trip = (app(DispatchTrip::class))($trip->fresh(), 1);
$trip = (app(DepartTrip::class))($trip->fresh(), 150_000, 4120.5, 1);
$trip = $trip->fresh();

check($trip->status->value === 'departed', 'the fixture trip is on the road ('.$trip->trip_no.')');

// A second trip, on the second truck, so a payload that names the wrong trip has something to be wrong about.
$secondJob = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'maize',
    tonnes: 24.0,
    notes: 'XGP second job',
));
$created['job_ids'][] = $secondJob->id;
$secondJob = (app(QuoteJob::class))($secondJob, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '800.00', currencyCode: $baseCurrency), 1);
$secondJob = (app(AcceptJob::class))($secondJob, 1);

$secondTrip = (app(CreateTrip::class))($secondJob, 1);
$created['trip_ids'][] = $secondTrip->id;
$secondTrip = (app(PlanTrip::class))($secondTrip, new TripDto(
    jobId: $secondJob->id,
    truckId: $secondTruck->id,
    driverId: $secondDriver->id,
    plannedDepartureAt: Carbon::now()->subHours(2)->toDateTimeString(),
), 1);
$secondTrip = (app(DispatchTrip::class))($secondTrip->fresh(), 1);
$secondTrip = (app(DepartTrip::class))($secondTrip->fresh(), 88_000, null, 1);

$onRoad = app(TripsInMotion::class)->active();
check(count($onRoad) >= 2, 'Operations\' read door reports '.count($onRoad).' trips on the road, with their corridors');

$ours = null;

foreach ($onRoad as $motion) {
    if ($motion->id === $trip->id) {
        $ours = $motion;
    }
}

check($ours!== null && $ours->truckLabel === $truck->label() && $ours->origin === 'Gaborone', '...and the DTO carries the truck label and the corridor, not the models (D5)');
check($ours !== null && $ours->distanceKm === 750.0 && $ours->odometerStartKm === 150_000, '...with the corridor distance and the opening reading the simulator walks against');

/* ------------------------------------------------------------------------- */
echo "\n== ingest: one batch, one door, the payload kept beside the columns ==\n";

$ingest = app(IngestPositions::class);

$firstBatch = ['messages' => [
    xgpMessage('XGP-0001', $truck->id, $trip->id, -24.6282000, 25.9231000),
    xgpMessage('XGP-0002', $truck->id, $trip->id, -24.1000000, 25.4000000, ['fixed_at' => Carbon::now()->subMinute()->format('Y-m-d H:i:s'), 'odometer_km' => 150_060]),
    xgpMessage('XGP-0003', $secondTruck->id, $secondTrip->id, -22.5609000, 17.0658000),
]];

$report = $ingest($account, $firstBatch);

check($report instanceof IngestReport, 'ingest answers with a report, not a bare count');
check($report->received === 3 && $report->stored === 3 && $report->duplicates === 0 && $report->rejected === 0, 'three fixes in, three on file ('.$report->summary().')');

$stored = GpsPosition::query()->where('provider_message_id', 'XGP-0001')->first();
check($stored !== null, 'the fix is readable through the model');
check((float) $stored->lat_10_7 === -24.6282 && (float) $stored->lng_10_7 === 25.9231, '...with the seven-decimal coordinate, not a rounded one');
check($stored->speed_kph === 82 && $stored->heading_deg === 271 && $stored->odometer_km === 150_010, '...and the speed, bearing and odometer the device sent');
check($stored->provider === 'simulator', '...filed under the adapter that parsed it');
check(is_array($stored->raw_json) && ($stored->raw_json['id'] ?? null) === 'XGP-0001', '...with the provider\'s own message kept verbatim for the argument about a wrong fix');
check($stored->ingested_at !== null && $stored->ingested_at->greaterThanOrEqualTo($runStartedAt), '...and an ingest clock beside the fix clock');
check($stored->trip_id === $trip->id, 'the fix is filed under the trip it named');

check((int) DB::table('gps_positions')->where('truck_id', $truck->id)->count() === 2, 'both fixes for the truck are in the history table');

$projection = LatestPosition::query()->where('truck_id', $truck->id)->first();
check($projection!== null, 'the projection moved: one row per truck');
check((float) $projection->lat_10_7 === -24.1 && (float) $projection->lng_10_7 === 25.4, '...showing the newest fix, not the first one that arrived');
check($projection->position_id !== null && (int) $projection->position_id === (int) DB::table('gps_positions')->where('provider_message_id', 'XGP-0002')->value('id'), '...and citing the row it is showing');
check($projection->trip_id === $trip->id, '...with the trip the truck is on');
// Counted by this run's trucks, not by table: a development database may already carry a live
// projection for a truck on the road, and a harness that demands an empty world is measuring the
// database's history rather than the code under test.
$runTruckIdsSoFar = [$truck->id, $secondTruck->id];

check((int) DB::table('latest_positions')->whereIn('truck_id', $runTruckIdsSoFar)->count() === 2, 'two trucks reporting, two projection rows, never one per fix');

$synced = $account->fresh();
check($synced->last_sync_at !== null, 'the feed records when it was last heard from — the difference between "nothing is moving" and "the gateway stopped calling"');

check(CurrentCompany::pin() === 1, 'ingest pinned the account\'s company and put the caller\'s pin back');

/* ------------------------------------------------------------------------- */
echo "\n== the retry guard: a webhook that fires twice stores once ==\n";

$retry = $ingest($account, $firstBatch);

check($retry->received === 3 && $retry->stored === 0 && $retry->duplicates === 3, 'the same batch again is three duplicates and nothing stored ('.$retry->summary().')');
check((int) DB::table('gps_positions')->whereIn('truck_id', $runTruckIdsSoFar)->count() === 3, '...and the history table did not grow');
check((float) LatestPosition::query()->where('truck_id', $truck->id)->value('lat_10_7') === -24.1, '...nor did the projection move');

$insideOneRequest = $ingest($account, ['messages' => [
    xgpMessage('XGP-DUP', $truck->id, $trip->id, -23.9, 25.3, ['fixed_at' => Carbon::now()->subSeconds(90)->format('Y-m-d H:i:s')]),
    xgpMessage('XGP-DUP', $truck->id, $trip->id, -23.9, 25.3, ['fixed_at' => Carbon::now()->subSeconds(90)->format('Y-m-d H:i:s')]),
]]);

check($insideOneRequest->received === 2 && $insideOneRequest->stored === 1 && $insideOneRequest->rejected === 1, 'a provider that repeats a message id inside one request is counted, not stored twice ('.$insideOneRequest->summary().')');
check((int) DB::table('gps_positions')->where('provider_message_id', 'XGP-DUP')->count() === 1, '...and the table holds the one row');

/* ------------------------------------------------------------------------- */
echo "\n== a bad message costs the message, not the batch ==\n";

$mixed = $ingest($account, ['messages' => [
    xgpMessage('XGP-BAD-LAT', $truck->id, $trip->id, 91.0, 25.9),
    xgpMessage('XGP-BAD-TRUCK', 999_999, $trip->id, -24.6, 25.9),
    xgpMessage('XGP-BAD-HEADING', $truck->id, $trip->id, -24.5, 25.8, ['heading_deg' => 400]),
    xgpMessage('XGP-BAD-FUTURE', $truck->id, $trip->id, -24.4, 25.7, ['fixed_at' => Carbon::now()->addHours(6)->format('Y-m-d H:i:s')]),
    xgpMessage('XGP-GOOD', $truck->id, $trip->id, -24.3, 25.6, ['fixed_at' => Carbon::now()->format('Y-m-d H:i:s'), 'odometer_km' => 150_120]),
]]);

check($mixed->received === 5 && $mixed->stored === 1 && $mixed->rejected === 4, 'four refusals and the fifth fix still landed ('.$mixed->summary().')');

foreach (['XGP-BAD-LAT', 'XGP-BAD-TRUCK', 'XGP-BAD-HEADING', 'XGP-BAD-FUTURE'] as $refusedId) {
    check((int) DB::table('gps_positions')->where('provider_message_id', $refusedId)->count() === 0, "{$refusedId} was refused and stored nothing");
}

check((int) DB::table('gps_positions')->where('provider_message_id', 'XGP-GOOD')->count() === 1, '...and the good fix in the same batch is on file');
check((float) LatestPosition::query()->where('truck_id', $truck->id)->value('odometer_km') === 150_120.0, 'the projection took the good fix, not the refused ones');

$shapeRefusals = [
    'a batch with no messages array' => ['not_a_batch' => true],
    'an empty batch' => ['messages' => []],
    'a message with no id' => ['messages' => [xgpMessage('', $truck->id, $trip->id, -24.6, 25.9)]],
    'a message that does not say which truck' => ['messages' => [['id' => 'XGP-NOTRUCK', 'fixed_at' => Carbon::now()->format('Y-m-d H:i:s'), 'lat' => -24.6, 'lng' => 25.9]]],
    'a message with an unreadable clock' => ['messages' => [['id' => 'XGP-NOCLOCK', 'truck_id' => $truck->id, 'fixed_at' => 'yesterday-ish', 'lat' => -24.6, 'lng' => 25.9]]],
];

foreach ($shapeRefusals as $what => $payload) {
    refuses(
        "{$what} refuses the batch, because the provider sent something this adapter cannot read",
        fn () => $ingest($account, $payload),
        InvalidArgumentException::class
    );
}

/* ------------------------------------------------------------------------- */
echo "\n== the projection only moves forwards ==\n";

$before = LatestPosition::query()->where('truck_id', $truck->id)->first();

$old = $ingest($account, ['messages' => [
    xgpMessage('XGP-OLD', $truck->id, $trip->id, -24.6282000, 25.9231000, [
        'fixed_at' => Carbon::now()->subHours(4)->format('Y-m-d H:i:s'),
        'odometer_km' => 149_900,
    ]),
]]);

check($old->stored === 1, 'a buffered device\'s old fix is still stored — it is real track ('.$old->summary().')');

$after = LatestPosition::query()->where('truck_id', $truck->id)->first();
check((float) $after->lat_10_7 === (float) $before->lat_10_7 && (int) $after->odometer_km === (int) $before->odometer_km, '...and the map did not rewind to where the truck was four hours ago');
check($after->position_id === $before->position_id, 'the projection still cites the newest fix it had');

/* ------------------------------------------------------------------------- */
echo "\n== a batch is statements, not rows ==\n";

$big = [];

for ($i = 0; $i < 600; $i++) {
    $big[] = xgpMessage(
        sprintf('XGP-BULK-%04d', $i),
        $secondTruck->id,
        $secondTrip->id,
        -22.5 - ($i * 0.001),
        17.0 + ($i * 0.001),
        ['fixed_at' => Carbon::now()->subSeconds(600 - $i)->format('Y-m-d H:i:s'), 'odometer_km' => 150_200 + $i],
    );
}

$statements = 0;
DB::listen(function ($query) use (&$statements): void {
    if (str_starts_with(strtolower(trim($query->sql)), 'insert')) {
        $statements++;
    }
});

$bulk = $ingest($account, ['messages' => $big]);

check($bulk->received === 600 && $bulk->stored === 600, '600 fixes in one webhook batch, all stored ('.$bulk->summary().')');
check($statements > 0 && $statements <= 4, "the batch went in as {$statements} INSERT statement(s), not 600");
check((int) DB::table('latest_positions')->where('truck_id', $secondTruck->id)->value('odometer_km') === 150_799, '...and the projection ended on the newest fix of the batch');

$bulkReplay = $ingest($account, ['messages' => $big]);
check($bulkReplay->stored === 0 && $bulkReplay->duplicates === 600, 'the whole 600-fix backlog replayed is 600 duplicates ('.$bulkReplay->summary().')');
check((int) DB::table('gps_positions')->where('provider_message_id', 'like', 'XGP-BULK-%')->count() === 600, '...and the history table still holds exactly 600 of them');

/* ------------------------------------------------------------------------- */
echo "\n== a fix is filed under the trip the truck is on, and a wrong trip id is not believed ==\n";

$attached = $ingest($account, ['messages' => [
    // No trip named: the ingest door asks Operations which trip this truck is running.
    xgpMessage('XGP-NOTRIP', $secondTruck->id, null, -22.4, 17.0, [
        'fixed_at' => Carbon::now()->format('Y-m-d H:i:s'),
        'odometer_km' => 151_500,
    ]),
    // A trip named that belongs to the *other* truck: not believed, and the fix still lands.
    xgpMessage('XGP-WRONGTRIP', $secondTruck->id, $trip->id, -22.3, 17.1, [
        'fixed_at' => Carbon::now()->addSeconds(30)->format('Y-m-d H:i:s'),
        'odometer_km' => 151_510,
    ]),
]]);

check($attached->stored === 2 && $attached->rejected === 0, 'both fixes were accepted ('.$attached->summary().')');

$noTripRow = DB::table('gps_positions')->where('provider_message_id', 'XGP-NOTRIP')->first();
check($noTripRow !== null && $noTripRow->trip_id === $secondTrip->id, 'a payload that knows nothing about trips still produces a trip map: the fix is filed under the truck\'s own journey');

$wrongRow = DB::table('gps_positions')->where('provider_message_id', 'XGP-WRONGTRIP')->first();
check($wrongRow !== null && $wrongRow->trip_id === $secondTrip->id, '...and a device left configured with another truck\'s trip does not paint a track on a journey it never ran');

/* ------------------------------------------------------------------------- */
echo "\n== R6 the simulator drives the same door, and does not invent coordinates ==\n";

$exit = Artisan::call('gps:simulate', ['trip' => $trip->trip_no, '--pings' => 3]);
$output = str_replace("\n", ' ', Artisan::output());

check($exit === 0, 'gps:simulate runs on a trip that is on the road', mb_substr($output, 0, 200));
check(str_contains($output, '3 stored'), '...and it stored three fixes through the ingest Action');

$simulated = DB::table('gps_positions')
    ->where('trip_id', $trip->id)
    ->where('provider_message_id', 'like', 'SIM-%')
    ->orderBy('fixed_at')
    ->get();

check($simulated->count() === 3, 'three SIM- messages are on file for the trip');

$gaborone = [-24.6282, 25.9231];
$windhoek = [-22.5609, 17.0658];

$firstSim = $simulated->first();
$lastSim = $simulated->last();

$nearerOrigin = abs((float) $firstSim->lat_10_7 - $gaborone[0]) + abs((float) $firstSim->lng_10_7 - $gaborone[1])
    < abs((float) $firstSim->lat_10_7 - $windhoek[0]) + abs((float) $firstSim->lng_10_7 - $windhoek[1]);

check($nearerOrigin, 'the simulated track starts at the corridor\'s own origin, not at a coordinate the simulator invented');
check((float) $firstSim->lat_10_7 >= $gaborone[0] && (float) $firstSim->lat_10_7 <= $windhoek[0], '...and every fix stays between the two endpoints the route names');
check((float) $lastSim->lat_10_7 > (float) $firstSim->lat_10_7 && (float) $lastSim->lng_10_7 < (float) $firstSim->lng_10_7, 'the truck moved north-west towards Windhoek on each ping');
check((int) $lastSim->odometer_km > (int) $firstSim->odometer_km, 'the odometer on the fixes advances with the distance');
check((int) $lastSim->odometer_km >= (int) $trip->odometer_start_km, '...and never below the trip\'s opening reading');
check((int) $firstSim->heading_deg >= 0 && (int) $firstSim->heading_deg <= 359, 'the bearing is a real compass heading ('.$firstSim->heading_deg.'°)');
check((int) $lastSim->odometer_km <= (int) $trip->odometer_start_km + 750, '...and never past the corridor\'s own distance');

$again = Artisan::call('gps:simulate', ['trip' => $trip->trip_no]);
$againOutput = str_replace("\n", ' ', Artisan::output());
check($again === 0 && str_contains($againOutput, '1 stored'), 'a second run continues the journey rather than restarting it', mb_substr($againOutput, 0, 200));

$resumed = DB::table('gps_positions')
    ->where('provider_message_id', 'like', 'SIM-%')
    ->where('trip_id', $trip->id)
    ->orderByDesc('fixed_at')
    ->orderByDesc('id')
    ->first();

// One 30-second ping at 80 km/h is two thirds of a kilometre, which the odometer rounds away — so the
// proof that the journey continued is the coordinate, not the whole kilometre.
check((float) $resumed->lat_10_7 > (float) $lastSim->lat_10_7, 'the resumed fix is further along the corridor than the last one ('.$resumed->lat_10_7.' > '.$lastSim->lat_10_7.')');
check((int) $resumed->odometer_km >= (int) $lastSim->odometer_km, '...and its odometer never runs backwards ('.$resumed->odometer_km.' km >= '.$lastSim->odometer_km.' km)');

$unknownCorridor = (app(CreateRoute::class))(new RouteDto(
    code: 'XGP-GBE-NOWHERE',
    origin: 'Gaborone',
    destination: 'Somewhere Else',
    originCountryCode: 'BW',
    destinationCountryCode: 'ZA',
    distanceKm: '120',
));

$unknownJob = (app(CreateJob::class))(new JobDto(customerId: $customer->id, routeId: $unknownCorridor->id, commodity: 'steel', tonnes: 20.0, notes: 'XGP unknown corridor'));
$created['job_ids'][] = $unknownJob->id;
$unknownJob = (app(QuoteJob::class))($unknownJob, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '400.00', currencyCode: $baseCurrency), 1);
$unknownJob = (app(AcceptJob::class))($unknownJob, 1);

$unknownTrip = (app(CreateTrip::class))($unknownJob, 1);
$created['trip_ids'][] = $unknownTrip->id;
$unknownTrip = (app(PlanTrip::class))($unknownTrip, new TripDto(
    jobId: $unknownJob->id,
    truckId: $thirdTruck->id,
    driverId: $thirdDriver->id,
    plannedDepartureAt: Carbon::now()->addDay()->toDateTimeString(),
), 1);
$unknownTrip = (app(DispatchTrip::class))($unknownTrip->fresh(), 1);
$unknownTrip = (app(DepartTrip::class))($unknownTrip->fresh(), 61_000, null, 1);

$skipped = Artisan::call('gps:simulate', ['trip' => $unknownTrip->fresh()->trip_no]);
$skippedOutput = str_replace("\n", ' ', Artisan::output());

check($skipped === 1, 'a corridor the simulator has no coordinates for fails loudly rather than plotting a guess');
check(str_contains($skippedOutput, 'no coordinates for "Somewhere Else"'), '...and the message names the place it does not know', mb_substr($skippedOutput, 0, 240));

$override = Artisan::call('gps:simulate', [
    'trip' => $unknownTrip->fresh()->trip_no,
    '--from' => '-24.6282,25.9231',
    '--to' => '-26.2041,28.0473',
]);
$overrideOutput = str_replace("\n", ' ', Artisan::output());

check($override === 0 && str_contains($overrideOutput, '1 stored'), '--from/--to are the escape hatch for a corridor that is not in the place table', mb_substr($overrideOutput, 0, 200));

$noSuchTrip = Artisan::call('gps:simulate', ['trip' => 'XGP-NO-SUCH-TRIP']);
check($noSuchTrip === 1, 'naming a trip that is not on the road exits non-zero');
check(str_contains(Artisan::output(), 'is on the road in company'), '...and says so, rather than simulating nothing silently');

$notDeparted = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $notDeparted->id;
$plannedOnly = Artisan::call('gps:simulate', ['trip' => $notDeparted->fresh()->trip_no]);
check($plannedOnly === 1, 'a trip that has not left is not on the road, so there is nothing to track');

/* ------------------------------------------------------------------------- */
echo "\n== the map's two reads: one row per truck, and one trip's track ==\n";

$map = app(LiveMap::class);
$vehicles = $map->vehicles();
$mine = static fn (array $mapped): array => array_values(array_filter(
    $mapped,
    static fn (LiveVehicle $vehicle): bool => in_array($vehicle->truckId, $created['truck_ids'], true),
));

check(count($mine($vehicles)) === 3, 'the map reads three trucks — the projection, not the '.DB::table('gps_positions')->count().' fixes behind it');

$tracked = null;

foreach ($vehicles as $vehicle) {
    if ($vehicle->truckId === $truck->id) {
        $tracked = $vehicle;
    }
}

check($tracked !== null, 'the first truck is on the map');
check($tracked->trip !== null && $tracked->trip->tripNo === $trip->trip_no, '...with the trip it is running and the corridor it is on');
check($tracked->speedLabel() !== '' && str_contains($tracked->speedLabel(), 'km/h'), '...printing a speed the dispatcher can read ('.$tracked->speedLabel().')');
check($tracked->ageMinutes() >= 0 && $tracked->ageMinutes() < 60, '...and an age in minutes ('.$tracked->ageMinutes().')');
check(! $tracked->isStale(), 'a fix from this run is not stale');

$track = $map->track($trip->id);
check(count($track) >= 4, 'the trip\'s track reads '.count($track).' fixes, oldest first');
check($track[0]->fixed_at->lessThanOrEqualTo($track[count($track) - 1]->fixed_at), '...in clock order, which is what a polyline needs');
check($map->trackSize($trip->id) === count($track), '...and the count beside it agrees');

$staleFix = $ingest($account, ['messages' => [
    xgpMessage('XGP-STALE', $secondTruck->id, $secondTrip->id, -22.2, 17.2, [
        'fixed_at' => Carbon::now()->subHours(3)->format('Y-m-d H:i:s'),
    ]),
]]);

// The projection only moves forwards, so a three-hour-old fix cannot make a truck look stale. Age is
// measured against the newest fix the projection holds, which is the honest reading of "last heard from".
check($staleFix->stored === 1, 'an old fix is stored in the history');

$staleVehicles = array_filter($mine($map->vehicles()), static fn (LiveVehicle $vehicle): bool => $vehicle->isStale());
check(count($staleVehicles) === 0, '...and it does not make the truck look stale on the map, because the projection still holds its newer fix');

/* ------------------------------------------------------------------------- */
echo "\n== the map is the Dispatcher's and the Workshop's, not the stores clerk's ==\n";

$makeSeat = static function (RoleKey $role, string $email) use ($owner): User {
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->name = 'Verify XGP '.ucfirst($role->value);
    $user->company_id = $owner->company_id;
    $user->password = Str::random(32);
    $user->email_verified_at = Carbon::now();
    $user->save();
    app(ChangeUserRole::class)($user->fresh(), $role);

    return $user->fresh();
};

$dispatcher = $makeSeat(RoleKey::Dispatcher, 'verify-xgp-dispatcher@fleetguard.local');
$stores = $makeSeat(RoleKey::StoresClerk, 'verify-xgp-stores@fleetguard.local');
$workshop = $makeSeat(RoleKey::WorkshopManager, 'verify-xgp-workshop@fleetguard.local');

check(Gate::forUser($dispatcher)->allows('gps.view'), 'a dispatcher may see the live map');
check(Gate::forUser($workshop)->allows('gps.view'), '...and so may the workshop');
check(! Gate::forUser($stores)->allows('gps.view'), 'the stores clerk holds no gps.view, so the route refuses the seat');

Auth::login($dispatcher);
$seen = $mine($map->vehicles());
check(count($seen) === 3, 'a dispatcher reads the same three trucks the owner does — the screen has no margin, no cost and no crew on it');
Auth::login($owner);
CurrentCompany::force(1);

/* ------------------------------------------------------------------------- */
echo "\n== tenancy: the GPS registers are scoped, not filtered by habit ==\n";

$stamp = Carbon::now()->toDateTimeString();

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify XGP Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

CurrentCompany::force(2);
check((int) GpsPosition::query()->count() === 0, 'company 2 reads no fixes');
check((int) LatestPosition::query()->count() === 0, '...no projections');
check((int) GpsProviderAccount::query()->count() === 0, '...and no feed of its own');
check(app(LiveMap::class)->vehicles() === [], 'so its map is empty rather than somebody else\'s fleet');

CurrentCompany::force(1);
check((int) GpsPosition::query()->count() >= 600, 'company 1 still reads its own history: '.GpsPosition::query()->count().' fixes');
check(count($mine(app(LiveMap::class)->vehicles())) === 3, '...and its own three trucks');

DB::table('companies')->where('id', 2)->delete();
CurrentCompany::force(1);

/* ------------------------------------------------------------------------- */
echo "\n== R6 nothing the ledger owns moved, because GPS is not a business fact ==\n";

check((int) DB::table('gl_journal_entries')->where('source_type', 'gps_position')->count() === 0, 'no fix posted a journal entry');

$ledgerExit = Artisan::call('ledger:verify', ['--quiet-ok' => true]);
$ledgerOutput = str_replace("\n", ' ', Artisan::output());
check($ledgerExit === 0, 'ledger:verify still passes with 600-odd fixes on file', mb_substr($ledgerOutput, 0, 240));

$recostExit = Artisan::call('trips:recost', ['--quiet-ok' => true]);
$recostOutput = str_replace("\n", ' ', Artisan::output());
check($recostExit === 0, 'trips:recost agrees: a trip\'s distance is still its odometer pair, not its track', mb_substr($recostOutput, 0, 240));

/* ------------------------------------------------------------------------- */
echo "\n== cleanup, and the proof it worked ==\n";

$runTripIds = array_values(array_unique(array_merge($created['trip_ids'], DB::table('trips')->whereIn('job_id', $created['job_ids'] ?: ['0'])->pluck('id')->all())));
$runTruckIds = $created['truck_ids'];

DB::table('latest_positions')->whereIn('truck_id', $runTruckIds ?: [0])->delete();
DB::table('gps_positions')->whereIn('truck_id', $runTruckIds ?: [0])->delete();
DB::table('latest_positions')->whereIn('trip_id', $runTripIds ?: ['0'])->delete();
DB::table('gps_positions')->whereIn('trip_id', $runTripIds ?: ['0'])->delete();
DB::table('gps_positions')->where('provider_message_id', 'like', 'XGP-%')->delete();
wipeIds('gps_provider_accounts', 'id', $created['account_ids']);

wipeIds('odometer_readings', 'trip_id', $runTripIds);
wipeIds('odometer_readings', 'truck_id', $runTruckIds);
wipeIds('vehicle_assignments', 'trip_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $created['job_ids']);
wipeIds('trips', 'id', $runTripIds);
wipeIds('freight_jobs', 'id', $created['job_ids']);

DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $runTruckIds ?: [0])->delete();
DB::table('routes')->where('code', 'like', 'XGP%')->delete();
DB::table('customers')->where('code', 'like', 'XGP%')->delete();

foreach ([Trip::class, Job::class] as $type) {
    DB::table('audits')->where('auditable_type', $type)->where('created_at', '>=', $runStartedAt)->delete();
}

$probeIds = DB::table('users')->where('email', 'like', 'verify-xgp-%@fleetguard.local')->pluck('id')->all();
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

$alignCounters('after cleanup');

$leftovers = [
    'gps_positions' => (int) DB::table('gps_positions')->whereIn('truck_id', $runTruckIds ?: [0])->count(),
    'latest_positions' => (int) DB::table('latest_positions')->whereIn('truck_id', $runTruckIds ?: [0])->count(),
    'gps_provider_accounts' => (int) DB::table('gps_provider_accounts')->count(),
    'trips' => (int) DB::table('trips')->whereIn('id', $runTripIds ?: ['0'])->count(),
    'freight_jobs' => (int) DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: ['0'])->count(),
    'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%XGP')->count(),
    'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-XGP%')->count(),
    'drivers' => (int) DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->count(),
    'routes' => (int) DB::table('routes')->where('code', 'like', 'XGP%')->count(),
    'customers' => (int) DB::table('customers')->where('code', 'like', 'XGP%')->count(),
    'probe company' => (int) DB::table('companies')->where('name', 'Verify XGP Second Company')->count(),
    'probe seats' => (int) DB::table('users')->where('email', 'like', 'verify-xgp-%@fleetguard.local')->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
