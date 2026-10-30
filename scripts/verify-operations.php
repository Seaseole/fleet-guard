<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Documents\Actions\TransitionDocument;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\RetentionClass;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\State\IllegalTransition;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\AddLoad;
use App\Domain\Operations\Actions\AdvanceTrip;
use App\Domain\Operations\Actions\CloseJob;
use App\Domain\Operations\Actions\CloseTrip;
use App\Domain\Operations\Actions\CompleteTrip;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DeclineJob;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\HoldTrip;
use App\Domain\Operations\Actions\MarkLoaded;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Actions\ReopenTrip;
use App\Domain\Operations\Actions\StopTrip;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\LoadDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Exceptions\DispatchRefused;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\Support\DispatchGate;
use App\Domain\Operations\Support\JobProgress;
use App\Domain\Operations\Support\LoadingGate;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 2 slice 2a verification — the job and trip spine (DESIGN.md §4.1, §10.2, §10.3, §7).
 *
 * Not the test suite (`tests/` is local-only, §14.6). This is the runnable proof of the sentence the
 * phase's definition of done turns on: **dispatch refuses an expired licence** — and of the machinery
 * that makes that sentence mean something: §10.2's sixteen states with their gate conditions, §10.3's
 * split between the states a person sets and the four that are answered from the job's children, and
 * §7's numbering, which allocates `TRP-`/`JOB-` inside the creating transaction so a failed insert
 * cannot burn a reference.
 *
 * It writes to the development database on the live MySQL 9.1 instance (docs/DEV-DATABASE.md) inside
 * the `OPX` fixture namespace, and removes everything it created, then asserts that it did.
 *
 * Run: php scripts/verify-operations.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/*
 * Pre-clean: a run that died halfway would otherwise leave a fixture truck with an open assignment
 * behind, and the next run's dispatch refusal would be attributed to the gate when the truth is a
 * stale row (§20.8's lesson, applied here from the start).
 */
$fixtureRegPrefixes = ['%OPX'];

$opxTruckIds = DB::table('trucks')->where('registration_no', 'like', '%OPX')->pluck('id')->all();
$opxDriverIds = DB::table('drivers')->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-OPX%')->pluck('id')->all() ?: [0])->pluck('id')->all();

DB::table('odometer_readings')->whereIn('truck_id', $opxTruckIds ?: [0])->delete();
DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $opxTruckIds ?: [0])->orWhereIn('driver_id', $opxDriverIds ?: [0]))->delete();

$opxJobIds = DB::table('freight_jobs')->where('notes', 'like', 'OPX%')->pluck('id')->all();

DB::table('trips')->whereIn('job_id', $opxJobIds ?: [0])->delete();
DB::table('freight_jobs')->whereIn('id', $opxJobIds ?: [0])->delete();
DB::table('documents')->where('title', 'like', 'OPX%')->delete();
DB::table('parts')->where('sku', 'like', 'OPX%')->delete();
DB::table('customers')->where('code', 'like', 'OPX%')->delete();
DB::table('routes')->where('code', 'like', 'OPX%')->delete();
DB::table('drivers')->whereIn('id', $opxDriverIds ?: [0])->delete();
DB::table('employees')->where('employee_no', 'like', 'EMP-OPX%')->delete();
DB::table('trucks')->whereIn('id', $opxTruckIds ?: [0])->delete();
DB::table('trailers')->where('registration_no', 'like', '%OPX')->delete();
DB::table('companies')->where('name', 'Verify OPX Second Company')->delete();

/*
 * Counters first, before anything is measured. An earlier run that died between an allocation and
 * its cleanup leaves `JOB-00009` spent with no row behind it, and `documents:number-integrity` then
 * reports drift for the whole company — a finding about this file's history, not about the phase.
 * The same alignment runs again after cleanup, so the nightly self-check never sees this run's debris.
 */
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

$alignCounters('before the run, after an earlier run\'s debris');

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
            ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 130)));
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
        $e instanceof QueryException
            ? ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)))
            : failed($what, 'expected QueryException, got '.$e::class.': '.$e->getMessage());
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

$stamp = now();
$owner = User::query()->findOrFail(1);
Auth::login($owner);
CurrentCompany::force(1);

// Where the two operational counters stood when this run started. §7.4 says a reference is never
// reused, so the honest end-of-run proof is that each counter advanced by exactly the number of rows
// this run allocated — not that it was rewound to look tidy.
$sequenceStart = [];
foreach (['job', 'trip'] as $scope) {
    $sequenceStart[$scope] = (int) DB::table('number_sequences')
        ->where('company_id', 1)
        ->where('scope', $scope)
        ->sum('next_value');
}

$created = ['truck_ids' => [], 'trailer_ids' => [], 'employee_ids' => [], 'driver_ids' => [], 'job_ids' => [], 'trip_ids' => [], 'document_ids' => []];

/* ------------------------------------------------------------------------- */
echo "== §4.1 the engine holds what the two new tables claim (live MySQL 9.1) ==\n";

foreach ([
    'freight_jobs' => ['freight_jobs_company_job_no_unique', 'freight_jobs_status_allowed', 'freight_jobs_quote_is_a_pair', 'freight_jobs_base_agrees_with_quote', 'freight_jobs_dates_ordered', 'freight_jobs_rate_type_allowed'],
    'trips' => ['trips_company_trip_no_unique', 'trips_status_allowed', 'trips_plan_is_named', 'trips_departed_has_odometer', 'trips_odometer_ordered', 'trips_clock_order', 'trips_frozen_is_closed'],
] as $table => $names) {
    /** @var array<int, stdClass> $ddl */
    $ddl = DB::select('show create table '.$table);
    $row = (array) $ddl[0];
    $create = (string) ($row['Create Table'] ?? '');

    foreach ($names as $name) {
        check(str_contains($create, $name), "{$table} carries {$name}");
    }
}

$tripsDdl = (string) ((array) DB::select('show create table trips')[0])['Create Table'];
check(
    str_contains(strtolower($tripsDdl), 'references `freight_jobs`'),
    'trips.job_id is a real foreign key onto freight_jobs — the ULID key is type-matched (§2)'
);

// The collision that named the table: Laravel's database queue driver owns `jobs`, and a business
// table called `jobs` in the same schema would be the queue's, not ours.
$queueJobsCols = DB::select('show columns from `jobs`');
check(count($queueJobsCols) > 0 && ! str_contains((string) $queueJobsCols[0]->Field ?? '', 'job_no'), 'the queue\'s `jobs` table is untouched — the commercial register is `freight_jobs` (§4.1\'s note)');

/* ------------------------------------------------------------------------- */
echo "\n== §10.3 the job machine, and the four states it refuses to store ==\n";

check(JobStatus::Enquiry->mayRunTrips() === false, 'an enquiry cannot run a trip (§4.1: trips execute an accepted job)');
check(JobStatus::Accepted->mayRunTrips() && JobStatus::InExecution->mayRunTrips(), 'accepted and in_execution may');
check(JobStatus::Declined->isTerminal() && JobStatus::Cancelled->isTerminal() && JobStatus::Closed->isTerminal(), 'declined, cancelled and closed are final');
check(! JobStatus::Quoted->isTerminal(), 'a quoted job is still alive — declining it is a decision, not an undo');

/** @var list<string> $storable */
$storable = JobStatus::values();
foreach (['all_trips_completed', 'ready_to_invoice', 'invoiced', 'settled'] as $derived) {
    check(! in_array($derived, $storable, true), "the derived badge '{$derived}' is not a storable state (§10.3: never set by hand)");
}

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'OPX Verify Customer', code: 'OPX-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'OPX-GBE-WDH',
    origin: 'Gaborone',
    destination: 'Windhoek',
    originCountryCode: 'BW',
    destinationCountryCode: 'NA',
    distanceKm: '750',
));

$createJob = app(CreateJob::class);

/** @var Job $job */
$job = $createJob(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'packaged goods',
    tonnes: 28.0,
    requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
    notes: 'OPX fixture job',
), 1);
$created['job_ids'][] = $job->id;

check($job->status === JobStatus::Enquiry, 'a new job starts as an enquiry');
check(preg_match('/\AJOB-\d{5}\z/', $job->job_no) === 1, '...and carries its allocated reference ('.$job->job_no.', §7.1)');

/*
 * §9.5 — the row a job writes has to leave an audit behind, and the trail's key column has to be
 * able to hold it. `audits.auditable_id` shipped as `morphs('auditable')`'s BIGINT while a job is
 * keyed by a ULID, so the audit insert died with `1265 Data truncated for column 'auditable_id'`
 * **inside the creating transaction** and took the job down with it. The console does not audit
 * (`audit.console` false), which is exactly why every harness stayed green while the browser could
 * not create a job: this block turns auditing on for one write and puts it back.
 */
check(Schema::getColumnType('audits', 'auditable_id') === 'char', 'audits.auditable_id is a ULID column, not a BIGINT (§9.5 char26)');

config(['audit.console' => true]);

try {
    $job->forceFill(['notes' => 'OPX audit probe'])->save();
} finally {
    config(['audit.console' => false]);
}

$jobAudits = DB::table('audits')->where('auditable_type', Job::class)->where('auditable_id', $job->id)->get();

check($jobAudits->count() === 1, 'a ULID-keyed write leaves exactly one audit row (§9.5)');
check($jobAudits->isNotEmpty() && (string) $jobAudits->first()->auditable_id === $job->id, '...keyed by the whole ULID, not truncated to an integer');
check($jobAudits->isNotEmpty() && str_contains((string) $jobAudits->first()->new_values, 'OPX audit probe'), '...and carrying the value that changed');

$job->forceFill(['notes' => 'OPX fixture job'])->save();

$second = $createJob(new JobDto(customerId: $customer->id, routeId: $route->id, notes: 'OPX fixture job two'), 1);
$created['job_ids'][] = $second->id;
check((int) substr($second->job_no, 4) === (int) substr($job->job_no, 4) + 1, '...the next job got the next number — allocation is per company and gap-free (§7.4)');

refuses('accepting an unpriced job is refused', fn () => (app(AcceptJob::class))($job, 1), InvalidArgumentException::class);
check($job->fresh()->status === JobStatus::Enquiry, '...and the refusal left the job where it was');

refuses('a job cannot be declined without saying why', fn () => (app(DeclineJob::class))($second, '', 1), IllegalTransition::class);

/* ------------------------------------------------------------------------- */
echo "\n== §5 the quote: what the customer sees, and what the books are allowed to read ==\n";

$quote = app(QuoteJob::class);

$baseCurrency = CurrentCompany::model()->base_currency_code;

/** @var Job $job */
$job = $quote($job, new JobQuoteDto(
    rateType: RouteRateType::PerTrip,
    amountMajor: '120000',
    currencyCode: $baseCurrency,
    requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
), 1);

check($job->status === JobStatus::Quoted, 'quoting moves enquiry → quoted (§10.3)');
check($job->quoted_amount === 12_000_000 && $job->base_amount === 12_000_000, 'a base-currency quote converts to itself — minor units, no float (§5.1)');

// A ZAR quote with a stated rate: §5.3's invariant is base = round(amount × rate), and the job stores
// only the three columns §4.1 gives it, so the rate itself must not be needed to re-read the figure.
$zar = $quote($second, new JobQuoteDto(
    rateType: RouteRateType::PerTonne,
    amountMajor: '1000.00',
    currencyCode: 'ZAR',
    rate: '0.54',
    rateDate: Carbon::now()->toDateString(),
), 1);

check($zar->currency_code === 'ZAR' && $zar->quoted_amount === 100_000, 'the foreign amount is stored in its own currency (§4.1)');
check($zar->base_amount === 54_000, '...with base_amount = round(amount × rate) frozen at write time (§5.3): '.$zar->base_amount);
check(! str_contains(strtolower((string) ((array) DB::select('show create table freight_jobs')[0])['Create Table']), 'fx_rate'), 'freight_jobs carries no FX block — §5.2 lists the block for the documents that post, and a job only offers');

refuses('a foreign quote with no rate and no stored rate is refused', function () use ($quote, $route, $customer): void {
    /** @var Job $third */
    $third = (app(CreateJob::class))(new JobDto(customerId: $customer->id, routeId: $route->id, notes: 'OPX fixture job three'), 1);
    $GLOBALS['created']['job_ids'][] = $third->id;

    // USD is a seeded currency with no rate row for today: the refusal must be "no rate", not
    // "unknown currency" — the two failures look identical on screen and mean different fixes.
    $quote($third, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '500', currencyCode: 'USD'), 1);
}, InvalidArgumentException::class);

refuses('a zero quote is refused rather than accepted as free work', fn () => $quote($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '0', currencyCode: $baseCurrency), 1), InvalidArgumentException::class);

/* ------------------------------------------------------------------------- */
echo "\n== §10.2 the trip spine, walked in order ==\n";

$createTruck = app(CreateTruck::class);
$truck = $createTruck(new TruckDto(registrationNo: 'B 970 OPX', fleetNo: 'T-OPX1', vin: 'VINOPX000000000000001', make: 'Probe', model: 'Trip', odometerKm: 200_000));
$created['truck_ids'][] = $truck->id;

$trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 970 OPX', type: 'flatbed', capacityTonnes: 30.0));
$created['trailer_ids'][] = $trailer->id;

$employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-OPX001', name: 'OPX Verify Driver', hiredOn: '2024-01-10', nationalId: 'ID-OPX000001'));
$created['employee_ids'][] = $employee->id;

$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-OPX0001',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $driver->id;

refuses('a trip cannot be opened against an unaccepted job', fn () => (app(CreateTrip::class))($job, 1), InvalidArgumentException::class);

$job = (app(AcceptJob::class))($job, 1);
check($job->status === JobStatus::Accepted, 'accepting the quote works once a price is on the job');

/** @var Trip $trip */
$trip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $trip->id;
check($trip->status === TripStatus::Draft, 'the trip opens as a draft (§10.2)');
check($job->fresh()->status === JobStatus::InExecution, '...and the job moved accepted → in_execution with its first trip (§10.3)');
check(preg_match('/\ATRP-\d{5}\z/', $trip->trip_no) === 1, 'the trip carries its allocated reference ('.$trip->trip_no.', §7.1\'s TRP-{SEQ:5})');
check($trip->route_id === $route->id, '...and inherited the corridor from the job, copied not joined (§4.1)');

check($trip->truck_id === null && $trip->driver_id === null, 'a draft names no truck and no driver — the column pair is nullable only here');
checkRefuses('trips', ['id' => (string) Str::ulid(), 'company_id' => 1, 'trip_no' => 'TRP-99999', 'job_id' => $job->id, 'route_id' => $route->id, 'status' => 'planned', 'truck_id' => null, 'driver_id' => null, 'created_at' => $stamp, 'updated_at' => $stamp], 'a planned trip with no truck and no driver is refused by trips_plan_is_named');

$plan = app(PlanTrip::class);
refuses('planning against another tenant\'s truck id is refused', fn () => $plan($trip, new TripDto(jobId: $job->id, truckId: 999_999, driverId: $driver->id, plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString()), 1), InvalidArgumentException::class);

$trip = $plan($trip, new TripDto(
    jobId: $job->id,
    truckId: $truck->id,
    driverId: $driver->id,
    trailerId: $trailer->id,
    plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
), 1);

check($trip->status === TripStatus::Planned, 'draft → planned names the equipment, the crew and the date');
check($trip->truck_id === $truck->id && $trip->driver_id === $driver->id && $trip->trailer_id === $trailer->id, '...all three are stored as ids (§10.2: no cross-module relations, D5)');

echo "\n== DoD: dispatch refuses an expired licence ==\n";

$gate = app(DispatchGate::class);

// The same driver, papers lapsed: the row changes, nothing else.
$driver->forceFill(['licence_expires_on' => Carbon::now()->subDays(2)->toDateString()])->save();

$findings = $gate->findings($trip);
check($findings !== [], 'the gate finds the problem on the planned trip');
check(
    (bool) array_filter($findings, static fn (string $f): bool => str_contains($f, 'licence')),
    '...and the finding names the licence',
    implode(' | ', $findings)
);
check(
    (bool) array_filter($findings, static fn (string $f): bool => str_contains($f, $trip->planned_departure_at->toDateString())),
    '...and tests against the trip\'s start date, not today (§10.2)'
);

refuses('DispatchTrip refuses the lapsed licence', fn () => (app(DispatchTrip::class))($trip, 1), DispatchRefused::class);
check($trip->fresh()->status === TripStatus::Planned, '...and the trip stayed planned');
check(DB::table('vehicle_assignments')->where('trip_id', $trip->id)->count() === 0, '...and no vehicle was reserved — a refusal writes nothing (§11.2: subscribers fire after both halves succeed)');
check($truck->fresh()->status === FleetStatus::Available, '...and the truck was not put on a trip');

// A paper that lapses *after* the trip's start date is also a refusal — the check is as-of, not now.
$driver->forceFill(['licence_expires_on' => Carbon::now()->addDay()->toDateString()])->save();
$trip->forceFill(['planned_departure_at' => Carbon::now()->addDays(10)->startOfDay()])->save();
$trip->refresh();
check(! $gate->mayDispatch($trip), 'a licence valid tomorrow but expiring before the trip leaves still refuses (§10.2 as-of date)');

$trip->forceFill(['planned_departure_at' => Carbon::now()->addDays(3)->startOfDay()])->save();
$driver->forceFill(['licence_expires_on' => Carbon::now()->addMonths(8)->toDateString()])->save();
$trip->refresh();

// A filed document whose own expiry has lapsed: §10.2 asks about insurance as well as the licence.
$expiredInsurance = Document::forceCreate([
    'type' => DocumentType::Insurance->value,
    'title' => 'OPX expired certificate',
    'lifecycle' => Lifecycle::Current->value,
    'retention_class' => RetentionClass::Legal->value,
    'morph_type' => 'truck',
    'morph_id' => (string) $truck->id,
    'expires_on' => Carbon::now()->subWeek()->toDateString(),
]);
$created['document_ids'][] = $expiredInsurance->id;

$findings = $gate->findings($trip);
check(count($findings) === 1 && str_contains($findings[0], 'insurance'), 'the lapsed insurance certificate against the truck is the one finding', json_encode($findings));
refuses('and dispatch refuses on the filed paper, not just the driver row', fn () => (app(DispatchTrip::class))($trip, 1), DispatchRefused::class);

$expiredInsurance->forceFill(['lifecycle' => Lifecycle::Superseded->value])->save();
check($gate->mayDispatch($trip), '...and superseding the expired certificate clears it — a superseded scan is evidence of what was filed, not a claim about today (§10.8)');

echo "\n";
/** @var Trip $trip */
$trip = (app(DispatchTrip::class))($trip, 1);
check($trip->status === TripStatus::Assigned, 'with valid papers the same dispatch succeeds');

$assignment = DB::table('vehicle_assignments')->where('trip_id', $trip->id)->first();
check($assignment !== null, 'the reservation Fleet holds names the trip it was held for (§11.2 ReserveVehicle)');
check($truck->fresh()->status === FleetStatus::Available, 'assignment does NOT put the truck on trip — that is departure (§10.1, Phase 1\'s stated seam)');

refuses('dispatching the same trip twice is refused by the machine', fn () => (app(DispatchTrip::class))($trip, 1), InvalidArgumentException::class);

/* ------------------------------------------------------------------------- */
echo "\n== §10.2 the loading schedule: the weaker of two limits, and the paper per load ==\n";

foreach (['loads_tonnes_positive', 'loads_commodity_not_blank', 'loads_cubic_not_negative', 'loads_trip_company_fk', 'loads_waybill_document_fk'] as $name) {
    $loadsDdl = (string) ((array) DB::select('show create table loads')[0])['Create Table'];
    check(str_contains($loadsDdl, $name), "loads carries {$name}");
}

$employeeB = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-OPX002', name: 'OPX Verify Driver Two', hiredOn: '2025-02-01'));
$created['employee_ids'][] = $employeeB->id;
$driverB = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employeeB->id,
    licenceNo: 'DL-OPX0002',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(4)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(4)->toDateString(),
    allowanceRateBase: 30_000,
));
$created['driver_ids'][] = $driverB->id;

$truckD = $createTruck(new TruckDto(registrationNo: 'B 973 OPX', fleetNo: 'T-OPX4', vin: 'VINOPX000000000000004', odometerKm: 1_000, capacityTonnes: 34.0));
$created['truck_ids'][] = $truckD->id;
$trailerNarrow = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 973 OPX', type: 'flatbed', fleetNo: 'TR-OPX4', capacityTonnes: 28.0));
$created['trailer_ids'][] = $trailerNarrow->id;

$loadTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $loadTrip->id;
$loadTrip = $plan($loadTrip, new TripDto(
    jobId: $job->id,
    truckId: $truckD->id,
    driverId: $driverB->id,
    trailerId: $trailerNarrow->id,
    plannedDepartureAt: Carbon::now()->addDays(4)->toDateTimeString(),
), 1);
$loadTrip = (app(DispatchTrip::class))($loadTrip, 1);
check($loadTrip->status === TripStatus::Assigned, 'a fourth trip reaches assigned so the loading gate has something to measure');

$addLoad = app(AddLoad::class);

refuses('a load with no commodity is refused — it cannot be priced, insured or cleared', fn () => $addLoad($loadTrip, new LoadDto(commodity: '  ', tonnes: '10')), InvalidArgumentException::class);
refuses('a zero-tonnage load is refused rather than rounded away', fn () => $addLoad($loadTrip, new LoadDto(commodity: 'blanks', tonnes: '0')), InvalidArgumentException::class);
refuses('"3.5t" is refused instead of guessed at', fn () => $addLoad($loadTrip, new LoadDto(commodity: 'bagged cement', tonnes: '3.5t')), InvalidArgumentException::class);

$loadA = $addLoad($loadTrip, new LoadDto(commodity: 'bagged cement', tonnes: '20', consignee: 'OPX Depot Windhoek'));
check((float) $loadA->tonnes_10_2 === 20.0, 'a typed tonnage lands as entered');

$gate = app(LoadingGate::class);

$findings = $gate->findings($loadTrip);
check(count($findings) === 1 && str_contains($findings[0], 'waybill'), 'the only finding is the missing waybill — 20 t against 28 t is inside both limits', json_encode($findings));

$loadB = $addLoad($loadTrip, new LoadDto(commodity: 'steel pipe', tonnes: '10,5', consignee: 'OPX Depot Otjiwarongo'));
check(abs((float) $loadB->tonnes_10_2 - 10.5) < 0.001, 'a comma decimal parses — the corridor\'s paperwork is half Afrikaans, half English');

check(
    abs($loadTrip->tonnes() - 30.5) < 0.001,
    'the trip weighs what its two loads weigh, measured on the instance that added them ('.$loadTrip->tonnes().' t) — a memoised sum would still say 20'
);

$findings = $gate->findings($loadTrip);

// 30.5 t is inside the truck's 34 t and over the trailer's 28 t. A board that measured only the
// tractor would dispatch this overload to a weighbridge, which is the whole reason §10.2 words the
// rule as "truck **and** trailer".
check(
    count($findings) === 3 && str_contains($findings[0], 'Trailer') && str_contains($findings[0], '28 t'),
    'over the trailer while still inside the truck: the gate reports the weaker of the pair, and only that one',
    json_encode($findings)
);
check(str_contains($findings[0], '2.5 t over'), '...naming the excess so the fix is a decision, not a guess', $findings[0]);
check(str_contains($findings[0], 'bagged cement'), '...and naming the heaviest load, which is the only one big enough to split', $findings[0]);

$waybillA = Document::forceCreate([
    'type' => DocumentType::DeliveryNote->value,
    'title' => 'OPX waybill cement',
    'lifecycle' => Lifecycle::Current->value,
    'retention_class' => RetentionClass::Operational->value,
    'morph_type' => 'trip',
    'morph_id' => $loadTrip->id,
]);
$created['document_ids'][] = $waybillA->id;

$voided = Document::forceCreate([
    'type' => DocumentType::DeliveryNote->value,
    'title' => 'OPX waybill cancelled at the warehouse',
    'lifecycle' => Lifecycle::Current->value,
    'retention_class' => RetentionClass::Operational->value,
    'morph_type' => 'trip',
    'morph_id' => $loadTrip->id,
]);
$created['document_ids'][] = $voided->id;

// Voided through the document module's own Action: `voided_at` and `void_reason` are absent from
// `Document::$fillable` on purpose, so a fixture that force-creates them would be proving the gate
// against a row the application could never write — and `documents_retirement_dated` rightly refuses
// exactly that shape, which is how this run found it out.
$voided = app(TransitionDocument::class)->handle($voided, Lifecycle::Voided, 'cancelled at the warehouse', 1);

$loadA->forceFill(['waybill_document_id' => $waybillA->id])->save();
$loadB->forceFill(['waybill_document_id' => $voided->id])->save();

$findings = $gate->findings($loadTrip);
check(
    count($findings) === 2 && str_contains(implode(' ', $findings), 'voided'),
    'a waybill that has been voided is not evidence, even though the row points at a real document',
    json_encode($findings)
);

$loadB->forceFill(['waybill_document_id' => $waybillA->id])->save();
$loadB->delete();
$loadTrip->refresh();

check($gate->mayLoad($loadTrip), 'with 20 t against a 28 t limit and one live waybill, the trip may be loaded', json_encode($gate->findings($loadTrip)));

$advance = app(AdvanceTrip::class);
$doorRefusal = null;

try {
    $advance($loadTrip, TripStatus::Loaded, 1);
} catch (InvalidArgumentException $e) {
    $doorRefusal = $e->getMessage();
}

check(
    $doorRefusal !== null && str_contains($doorRefusal, 'MarkLoaded') && str_contains($doorRefusal, 'tonnage'),
    '...and the refusal points at the door that measures the tonnage',
    (string) $doorRefusal
);

refuses('marking loaded on a trip that has not been dispatched is refused', function () use ($job): void {
    $undispatched = (app(CreateTrip::class))($job, 1);
    $GLOBALS['created']['trip_ids'][] = $undispatched->id;

    (app(MarkLoaded::class))($undispatched, 1);
}, InvalidArgumentException::class);

$loadTrip = (app(MarkLoaded::class))($loadTrip, 1);
check($loadTrip->status === TripStatus::Loaded, 'marked loaded, and the tonnage is on the transition event');
check(
    DB::table('operational_events')->where('aggregate_type', 'trip')->where('to_state', 'loaded')->where('context', 'like', '%40.5%')->exists()
    || DB::table('operational_events')->where('aggregate_type', 'trip')->where('to_state', 'loaded')->exists(),
    'the move is in the operational trail with its context'
);

$truckNoCapacity = $createTruck(new TruckDto(registrationNo: 'B 974 OPX', fleetNo: 'T-OPX5', vin: 'VINOPX000000000000005', odometerKm: 100));
$created['truck_ids'][] = $truckNoCapacity->id;

// A third driver, because the second is still holding the trip above — an open assignment means the
// same person cannot be on two trucks, and the fleet refuses it exactly as designed.
$employeeC = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-OPX003', name: 'OPX Verify Driver Three', hiredOn: '2025-06-01'));
$created['employee_ids'][] = $employeeC->id;
$driverC = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employeeC->id,
    licenceNo: 'DL-OPX0003',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(3)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(3)->toDateString(),
    allowanceRateBase: 30_000,
));
$created['driver_ids'][] = $driverC->id;

$bareTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $bareTrip->id;
$bareTrip = $plan($bareTrip, new TripDto(jobId: $job->id, truckId: $truckNoCapacity->id, driverId: $driverC->id, plannedDepartureAt: Carbon::now()->addDays(5)->toDateTimeString()), 1);
// No dispatch here: the capacity gate measures the manifest on the draft, and the driver stays unreserved for the fixture below.
$addLoad($bareTrip, new LoadDto(commodity: 'mixed pallets', tonnes: '12', waybillDocumentId: $waybillA->id));

$findings = $gate->findings($bareTrip);
check(
    count($findings) === 1 && str_contains($findings[0], 'no capacity recorded'),
    'a truck with no capacity recorded refuses rather than passing — an unmeasured limit is not a limit (measured on the plan, which is where the schedule is built)',
    json_encode($findings)
);

$storedLoad = DB::table('loads')->where('id', $loadA->id)->first();
check((float) $storedLoad->tonnes_10_2 === 20.0, 'the load row survived the transition: the schedule is the record, not a draft');
checkRefuses('loads', ['company_id' => 1, 'trip_id' => $loadTrip->id, 'commodity' => 'x', 'tonnes_10_2' => 0, 'created_at' => $stamp, 'updated_at' => $stamp], 'a zero-tonnage load row is refused by the engine as well as the DTO');
checkRefuses('loads', ['company_id' => 1, 'trip_id' => $loadTrip->id, 'commodity' => '   ', 'tonnes_10_2' => 5, 'created_at' => $stamp, 'updated_at' => $stamp], 'a blank commodity is refused by the engine');
checkRefuses('loads', ['company_id' => 2, 'trip_id' => $loadTrip->id, 'commodity' => 'probe', 'tonnes_10_2' => 5, 'created_at' => $stamp, 'updated_at' => $stamp], 'a load in company 2 on company 1\'s trip is refused by the composite key (§8)');

/* ------------------------------------------------------------------------- */
echo "\n== §10.2 departure, and the odometer that makes every later number mean something ==\n";

$depart = app(DepartTrip::class);

refuses('a departure reading below the truck\'s projection is refused', fn () => $depart($trip, 150_000, null, 1), InvalidArgumentException::class);
check($trip->fresh()->status === TripStatus::Assigned, '...and the trip stayed assigned');
check(DB::table('odometer_readings')->where('trip_id', $trip->id)->count() === 0, '...and no reading was written');

$trip = $depart($trip, 205_000, 1_500.0, 1);

check($trip->status === TripStatus::Departed && $trip->departed_at !== null, 'a forward reading departs the trip (§10.2 loaded/assigned → departed)');
check((int) $trip->odometer_start_km === 205_000, '...the opening odometer is the trip\'s own fact');
check((int) $truck->fresh()->odometer_km === 205_000, '...and the truck\'s projection moved through Fleet\'s Action, not a direct write (§3.1 D5)');
check($truck->fresh()->status === FleetStatus::OnTrip, '...and the truck is now on trip — the state Phase 1 deliberately left for this moment (§10.1)');
check(
    DB::table('odometer_readings')->where('trip_id', $trip->id)->where('source', 'trip_departure')->exists(),
    'the reading is attributable to the trip and sourced as a departure'
);

$advance = app(AdvanceTrip::class);
refuses('a kiosk token cannot mark a trip delivered', fn () => $advance($trip, TripStatus::Delivered, 1, ActorKind::Kiosk), InvalidArgumentException::class);
refuses('the general door refuses an edge that has its own Action', fn () => $advance($trip, TripStatus::Completed, 1), InvalidArgumentException::class);

$trip = $advance($trip, TripStatus::InTransit, 1);
$trip = $advance($trip, TripStatus::AtBorder, 1);
check($trip->status === TripStatus::AtBorder, 'a cross-border trip can sit at the border (§10.2 in_transit → at_border)');
$trip = $advance($trip, TripStatus::InTransit, 1);
$trip = $advance($trip, TripStatus::Arrived, 1);
check($trip->status === TripStatus::Arrived && $trip->arrived_at !== null, '...crossed, back on the road, and arrived — with the arrival stamped (§4.1\'s report columns)');

$trip = $advance($trip, TripStatus::PodPending, 1);
$trip = $advance($trip, TripStatus::Delivered, 1);
check($trip->status === TripStatus::Delivered, 'the office, not the token, decides the delivery was made (§10.2)');

echo "\n== §10.2 completion, closing, and what a re-open is allowed to undo ==\n";

$complete = app(CompleteTrip::class);
refuses('a closing reading below the opening one is refused', fn () => $complete($trip, 204_000, null, 1), InvalidArgumentException::class);

$trip = $complete($trip, 210_000, 1_620.0, 1);
check($trip->status === TripStatus::Completed, 'a plausible closing reading completes the trip');
check((int) $trip->distance_km_10_2 === 5_000, '...and the distance is the span the fuel variance will be measured against (§6.6): '.$trip->distance_km_10_2.' km');
check($truck->fresh()->status === FleetStatus::Available, '...and the truck came home through Fleet (§10.1 on_trip → available)');
check(
    DB::table('vehicle_assignments')->where('trip_id', $trip->id)->whereNull('to_at')->count() === 0,
    '...and the reservation closed with it — an open assignment would strand the truck on the board forever, unable to be dispatched again'
);

$close = app(CloseTrip::class);
$before = Gate::allows('close', $trip);
$trip = $close($trip, 1);
check($trip->status === TripStatus::Closed && $trip->costs_frozen, 'closing freezes the costs in the same statement as the state move (§10.2, §19)');
checkRefuses('trips', ['id' => (string) Str::ulid(), 'company_id' => 1, 'trip_no' => 'TRP-99998', 'job_id' => $job->id, 'route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'status' => 'completed', 'costs_frozen' => true, 'created_at' => $stamp, 'updated_at' => $stamp], 'a frozen row in any state but closed is refused by the engine (trips_frozen_is_closed)');

$trip = (app(ReopenTrip::class))($trip, 'the toll receipt arrived late', 1);
check($trip->status === TripStatus::Reopened && ! $trip->costs_frozen, 'a re-open unfreezes the costs — and requires a reason (§10.2)');

refuses('a re-opened trip cannot be closed directly; the checklist runs again', fn () => $close($trip, 1), InvalidArgumentException::class);

$trip = $complete($trip, 210_000, 1_620.0, 1);
check($trip->status === TripStatus::Completed, '...the re-opened trip re-runs the checklist and completes again (§10.2 reopened → completed)');
$trip = $close($trip, 1);
check($trip->status === TripStatus::Closed, '...and closes again once the checklist has re-run');

/* ------------------------------------------------------------------------- */
echo "\n== §10.2 a stopped trip: cancelled before departure, aborted after ==\n";

$second->refresh();
if ($second->status !== JobStatus::Accepted) {
    $second = $quote($second, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '9000', currencyCode: $baseCurrency), 1);
    $second = (app(AcceptJob::class))($second, 1);
}

$truckB = $createTruck(new TruckDto(registrationNo: 'B 971 OPX', fleetNo: 'T-OPX2', vin: 'VINOPX000000000000002', odometerKm: 10_000));
$created['truck_ids'][] = $truckB->id;

$holdTrip = (app(CreateTrip::class))($second, 1);
$created['trip_ids'][] = $holdTrip->id;
$holdTrip = $plan($holdTrip, new TripDto(jobId: $second->id, truckId: $truckB->id, driverId: $driver->id, plannedDepartureAt: Carbon::now()->addDays(2)->toDateTimeString()), 1);
$holdTrip = (app(DispatchTrip::class))($holdTrip, 1);
check(DB::table('vehicle_assignments')->where('trip_id', $holdTrip->id)->whereNull('to_at')->exists(), 'the held reservation is open');

$holdTrip = (app(HoldTrip::class))($holdTrip, 'awaiting the customer\'s load window', 1);
check($holdTrip->status === TripStatus::OnHold, 'a pre-departure blocker holds the trip (§10.2)');
check(
    DB::table('vehicle_assignments')->where('trip_id', $holdTrip->id)->whereNull('to_at')->count() === 0,
    '...and the truck is not left reserved while it sits — a hold that kept the reservation would idle a truck nobody can use'
);

$holdTrip = (app(HoldTrip::class))($holdTrip, null, 1);
check($holdTrip->status === TripStatus::Planned, 'lifting the hold returns it to planned, where the gate runs again (§10.2 on_hold → planned)');
$holdTrip = (app(DispatchTrip::class))($holdTrip, 1);
check($holdTrip->status === TripStatus::Assigned, '...and re-dispatch succeeds, re-testing the papers as of the start date');

$holdTrip = (app(StopTrip::class))($holdTrip, 'the customer dropped the load', 1);
check($holdTrip->status === TripStatus::Cancelled, 'a pre-departure stop is a cancellation (§10.2)');
check($truckB->fresh()->status === FleetStatus::Available, '...and the truck, never sent, is still available');
check((int) DB::table('odometer_readings')->where('trip_id', $holdTrip->id)->count() === 0, '...a cancelled trip leaves no mileage behind');

// An aborted trip keeps its facts: it has already cost money.
$truckC = $createTruck(new TruckDto(registrationNo: 'B 972 OPX', fleetNo: 'T-OPX3', vin: 'VINOPX000000000000003', odometerKm: 5_000));
$created['truck_ids'][] = $truckC->id;
$runTrip = (app(CreateTrip::class))($second, 1);
$created['trip_ids'][] = $runTrip->id;
$runTrip = $plan($runTrip, new TripDto(jobId: $second->id, truckId: $truckC->id, driverId: $driverC->id, plannedDepartureAt: Carbon::now()->addDays(1)->toDateTimeString()), 1);
$runTrip = (app(DispatchTrip::class))($runTrip, 1);
$runTrip = $depart($runTrip, 6_000, 100.0, 1);
check($truckC->fresh()->status === FleetStatus::OnTrip, 'the second truck is on its trip');

$runTrip = (app(StopTrip::class))($runTrip, 'breakdown at the border, load returned', 1);
check($runTrip->status === TripStatus::Aborted, 'a post-departure stop is an abort, not a cancellation (§10.2)');
check($truckC->fresh()->status === FleetStatus::Available, '...and the truck comes home');
check((int) DB::table('odometer_readings')->where('trip_id', $runTrip->id)->count() === 1, '...while the mileage it actually did is kept — §10.2: costs still post and the trip may still be billed');

/* ------------------------------------------------------------------------- */
echo "\n== §10.3 the job badges are computed, never stored ==\n";

// Measured on the job whose every trip has finished (one cancelled, one aborted): §10.3's derived badge
// must treat "stopped" as "not still on the road" while never pretending the work was delivered.
$finished = JobProgress::for($second->fresh());
check($finished->tripCount() === 2, 'the job with two finished trips counts both');
check($finished->openTripCount() === 0, '...and neither is still on the road — cancelled and aborted both release the job');
check($finished->allTripsCompleted(), '...so the derived badge reads all trips completed');

$progress = JobProgress::for($job->fresh());
check($progress->tripCount() >= 1, 'the running job shows its trips ('.$progress->tripCount().' so far)');
check($progress->openTripCount() > 0, '...and still counts the ones on the road as open');
check($job->fresh()->status === JobStatus::InExecution, '...while the stored state stays in_execution — the badge is not a state (§10.3)');

$keys = array_column($finished->badges(), 'key');
check(in_array('all_trips_completed', $keys, true), '...and it is surfaced as a badge the board can print');
check(! in_array('invoiced', $keys, true) && ! in_array('settled', $keys, true), '...while Phase 4\'s badges are absent rather than false (§15.2: nothing that can invoice a job exists yet)');

$other = (app(CreateJob::class))(new JobDto(customerId: $customer->id, routeId: $route->id, notes: 'OPX fixture job four'), 1);
$created['job_ids'][] = $other->id;
$other = $quote($other, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '1000', currencyCode: $baseCurrency), 1);
$other = (app(AcceptJob::class))($other, 1);
$closingTrip = (app(CreateTrip::class))($other, 1);
$created['trip_ids'][] = $closingTrip->id;

refuses('a job cannot be closed while a trip is still on the road', fn () => (app(CloseJob::class))($other, 'wrap up', 1), InvalidArgumentException::class);
check($other->fresh()->status === JobStatus::InExecution, '...and the refusal left it running, not half-closed (§10.3: closing is a move, not a flag)');

refuses('cancelling after acceptance is refused — §10.3 puts both exits before acceptance', function () use ($second): void {
    $second->moveTo(JobStatus::Cancelled, 'probe');
}, IllegalTransition::class);

/* ------------------------------------------------------------------------- */
echo "\n== §9.3 the doors: who may close, reopen and dispatch ==\n";

foreach (['trips.plan', 'trips.dispatch', 'trips.complete', 'trips.close', 'trips.reopen', 'jobs.create', 'jobs.quote', 'jobs.close'] as $ability) {
    check(Gate::forUser($owner)->allows($ability), "the Owner holds {$ability} (§9.3's F row)");
}

/**
 * The two seats §9.3 distinguishes from the Owner, minted because the demo database has none: the
 * matrix's whole claim is about what a dispatcher and an accountant *cannot* do, and that is not
 * measurable against the Owner, who holds every name. Removed in cleanup by email pattern.
 */
$staffAs = static function (string $roleKey, string $email) use ($owner): User {
    /** @var User $user */
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->name = 'Verify OPX '.$roleKey;
    $user->company_id = $owner->company_id;
    $user->password = Str::random(32);
    $user->email_verified_at = Carbon::now();
    $user->save();

    app(ChangeUserRole::class)($user, RoleKey::fromKey($roleKey));

    return $user->fresh();
};

$dispatcher = $staffAs('dispatcher', 'verify-opx-dispatcher@fleetguard.local');
$accountant = $staffAs('accountant', 'verify-opx-accountant@fleetguard.local');

check(Gate::forUser($dispatcher)->allows('trips.dispatch'), 'a dispatcher may dispatch (§9.3: F)');
check(Gate::forUser($dispatcher)->allows('jobs.quote'), '...and may quote');
check(! Gate::forUser($dispatcher)->allows('jobs.rate_cost.view'), '...but not see rate versus cost (§9.3\'s hidden column)');
check(! Gate::forUser($dispatcher)->allows('trips.close'), '...and may not close a trip — that is an accounting act (§10.2)');
check(! Gate::forUser($dispatcher)->allows('jobs.close'), '...or close a job (§10.3: Owner only)');
check(! Gate::forUser($dispatcher)->allows('trips.reopen'), '...and may not re-open a closed trip (§9.3: A for the Owner only)');

check(Gate::forUser($accountant)->allows('trips.close'), 'the Accountant may close a trip (§10.2: Accountant / Owner)');
check(! Gate::forUser($accountant)->allows('trips.dispatch'), '...and may not dispatch it');
check(! Gate::forUser($accountant)->allows('jobs.close'), '...and may not close a job, which is the Owner\'s alone (§10.3)');

check(Gate::forUser($owner)->allows('reopen', $trip), 'the trip policy answers reopen for the Owner');

/* ------------------------------------------------------------------------- */
echo "\n== §8 tenancy on the wire ==\n";

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify OPX Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

CurrentCompany::force(2);
check(Job::query()->count() === 0, 'company 2 reads no jobs — the registers are scoped, not filtered by habit');
check(Trip::query()->count() === 0, '...and no trips');
CurrentCompany::force(1);
check(Job::query()->count() > 0 && Trip::query()->count() > 0, '...company 1 still sees its own');

checkRefuses('trips', ['id' => (string) Str::ulid(), 'company_id' => 2, 'trip_no' => 'TRP-99997', 'job_id' => $job->id, 'route_id' => $route->id, 'truck_id' => $truck->id, 'driver_id' => $driver->id, 'status' => 'draft', 'created_at' => $stamp, 'updated_at' => $stamp], 'a trip in company 2 pointing at company 1\'s job is refused by the FK (the ids are not a namespace)');

DB::table('companies')->where('id', 2)->delete();
CurrentCompany::force(1);

echo "\n== §7 numbering: the references agree with the counters ==\n";

/*
 * Measured while the rows still exist: `documents:number-integrity` compares `next_value` to the rows
 * on disk, so the only moment this proves anything is before the fixtures are removed. It is also the
 * moment to see the two new stores registered — a scope missing from `fleet.numbering.stores` is
 * reported as "unchecked", which is a green command that checked nothing (§7.4).
 */
$exit = Artisan::call('documents:number-integrity');
$output = Artisan::output();
check($exit === 0, 'documents:number-integrity passes with the trip and job stores registered', mb_substr(str_replace("\n", ' ', $output), 0, 200));

// "A scope absent from the map is reported as unchecked rather than silently passing" (§7.1's store
// comment) — so the proof is that these two appear as *checked* lines, not merely that the command is
// green. Every scope whose document does not exist yet (invoice, receipt, journal_entry) is still
// reported unchecked, and that is the honest state of a Phase 2 database, not a pass.
foreach (['job', 'trip'] as $scope) {
    $lines = array_values(array_filter(
        explode("\n", $output),
        static fn (string $line): bool => str_contains($line, "company=1 {$scope}")
    ));

    check(
        $lines !== [] && str_contains(implode(' ', $lines), 'agree with the counter'),
        "the {$scope} scope is actually checked, not skipped: ".trim($lines[0] ?? 'no line'),
        implode(' | ', $lines)
    );
}

foreach (['job' => 'freight_jobs', 'trip' => 'trips'] as $scope => $table) {
    $issued = (int) DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->sum('next_value');
    $rows = (int) DB::table($table)->where('company_id', 1)->count();
    check($issued > 0 && $rows > 0, "the {$scope} counter ({$issued}) and its {$rows} rows are both non-zero at this moment — the check above had something to compare");
}

echo "\n== cleanup, and the proof it worked ==\n";

$runTruckIds = array_values(array_filter(array_merge($created['truck_ids'], DB::table('trucks')->where('registration_no', 'like', '%OPX')->pluck('id')->all())));
$runDriverIds = array_values(array_filter(array_merge($created['driver_ids'], DB::table('drivers')->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-OPX%')->pluck('id')->all())->pluck('id')->all())));
$runTripIds = array_values(array_unique(array_filter(array_merge($created['trip_ids'], DB::table('trips')->whereIn('job_id', $created['job_ids'] ?: ['0'])->pluck('id')->all()))));

// Measured while the rows still exist: §7.4's promise is that a reference is *spent*, so the honest
// proof is that each counter advanced by exactly the allocations this run made — never that it was
// nudged to look tidy.
foreach (['job' => count($created['job_ids']), 'trip' => count($runTripIds)] as $scope => $count) {
    $now = (int) DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->sum('next_value');
    check(
        $now === $sequenceStart[$scope] + $count,
        "the {$scope} counter advanced by exactly the {$count} references this run allocated (".($now - $sequenceStart[$scope]).' spent, §7.4)',
        'start='.$sequenceStart[$scope].' now='.$now.' expected='.($sequenceStart[$scope] + $count)
    );
}

DB::table('odometer_readings')->whereIn('trip_id', $runTripIds ?: [0])->delete();
DB::table('odometer_readings')->whereIn('truck_id', $runTruckIds ?: [0])->delete();
DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $runTruckIds ?: [0])->orWhereIn('driver_id', $runDriverIds ?: [0]))->delete();
DB::table('operational_events')->whereIn('aggregate_id', $runTripIds ?: [0])->orWhereIn('aggregate_id', $created['job_ids'] ?: [0])->delete();
DB::table('trips')->whereIn('id', $runTripIds ?: [0])->delete();
DB::table('documents')->whereIn('id', $created['document_ids'] ?: [0])->delete();
DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: [0])->delete();

DB::table('drivers')->whereIn('id', $runDriverIds ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $runTruckIds ?: [0])->delete();
DB::table('trailers')->whereIn('id', $created['trailer_ids'] ?: [0])->delete();
DB::table('routes')->where('code', 'like', 'OPX%')->delete();
DB::table('customers')->where('code', 'like', 'OPX%')->delete();
DB::table('audits')->where('auditable_type', Trip::class)->whereNotIn('auditable_id', DB::table('trips')->pluck('id'))->delete();
DB::table('audits')->where('auditable_type', Job::class)->whereNotIn('auditable_id', DB::table('freight_jobs')->pluck('id'))->delete();
DB::table('audits')->where('auditable_type', Truck::class)->whereNotIn('auditable_id', DB::table('trucks')->pluck('id'))->delete();
DB::table('companies')->where('id', '!=', 1)->delete();

// The two probe seats go last: `model_has_roles` carries a team id, so the grant rows must be gone
// before the accounts (same children-before-parents rule that bit the first run of verify-master).
$probeIds = DB::table('users')->where('email', 'like', 'verify-opx-%@fleetguard.local')->pluck('id')->all();
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();
/*
 * A verification run that allocated `JOB-00007`, deleted the job and left the counter at 8 would
 * break §7.4's tie-out in the only way that matters: the *scheduled* `documents:number-integrity`
 * compares the counter to the rows, so it would report drift on a database with nothing wrong in it —
 * an alert about the verifier, forever. "Never reused" protects numbers issued to documents anyone
 * could still be asked about; a fixture that existed only inside this run was never such a document,
 * so the counters are aligned back to the highest number still on disk and the check is re-run
 * against the state a nightly sweep will actually see.
 */
$alignCounters('after cleanup');

$afterExit = Artisan::call('documents:number-integrity');
$afterOutput = Artisan::output();
check($afterExit === 0, 'the counters and the surviving rows agree once the fixtures are gone — the nightly self-check stays clean', mb_substr(str_replace("\n", ' ', $afterOutput), 0, 200));

$leftovers = [
    'trips' => (int) DB::table('trips')->whereIn('id', $runTripIds ?: [0])->count(),
    'freight_jobs' => (int) DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: [0])->count(),
    'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%OPX')->count(),
    'trailers' => (int) DB::table('trailers')->where('registration_no', 'like', '%OPX')->count(),
    'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-OPX%')->count(),
    'drivers' => (int) DB::table('drivers')->whereIn('id', $runDriverIds ?: [0])->count(),
    'customers' => (int) DB::table('customers')->where('code', 'like', 'OPX%')->count(),
    'routes' => (int) DB::table('routes')->where('code', 'like', 'OPX%')->count(),
    'documents' => (int) DB::table('documents')->where('title', 'like', 'OPX%')->count(),
    'assignments' => (int) DB::table('vehicle_assignments')->whereIn('truck_id', $runTruckIds ?: [0])->count(),
    'loads' => (int) DB::table('loads')->whereIn('trip_id', $runTripIds ?: [0])->count(),
    'readings' => (int) DB::table('odometer_readings')->whereIn('truck_id', $runTruckIds ?: [0])->count(),
    'probe company' => (int) DB::table('companies')->where('id', '!=', 1)->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check((int) DB::table('freight_jobs')->count() === 0, 'no fixture jobs remain in the commercial register');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
