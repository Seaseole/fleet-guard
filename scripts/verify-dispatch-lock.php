<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\AssignVehicle;
use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
 * §15.2's concurrency proof for the dispatch path: `lockForUpdate` is only worth having if the lock is
 * (a) actually emitted, (b) actually waited on, and (c) actually decides the answer.
 *
 * The other harnesses cannot see this. `verify-operations.php` runs one actor at a time, so every
 * check-then-act race it contains is a race that never happens; `render-operations.php` renders screens.
 * This file holds a row open on a **second connection** — a real second session in the same MySQL — and
 * asks what the dispatch Action does while it waits.
 *
 * Fixtures are prefixed `LCK` and are deleted in `finally`, with the counters aligned afterwards, for the
 * same reason §20.8 gives for `verify-operations.php`: a probe that dies mid-run must not leave a truck
 * holding an open window.
 *
 * Run: php scripts/verify-dispatch-lock.php
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks;
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

/** §20.8's habit: whatever happened to the fixture truck, the probe connection and the counters are put back. */
function cleanUp(): void
{
    $truckIds = DB::table('trucks')->where('registration_no', 'like', '%LCK')->pluck('id')->all();
    $driverIds = DB::table('drivers')->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'EMP-LCK%')->pluck('id')->all() ?: [0])->pluck('id')->all();
    $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'LCK%')->pluck('id')->all();

    DB::table('vehicle_assignments')->where(static fn ($q) => $q->whereIn('truck_id', $truckIds ?: [0])->orWhereIn('driver_id', $driverIds ?: [0]))->delete();
    DB::table('odometer_readings')->whereIn('truck_id', $truckIds ?: [0])->delete();

    $tripIds = DB::table('trips')->whereIn('job_id', $jobIds ?: ['0'])->pluck('id')->all();

    // A `char(26)` key compared against an integer makes MySQL cast the *column* to a number, and a ULID
    // is not a number: error 1292 on the cleanup of a run that had already deleted its own trips.
    if ($tripIds !== []) {
        DB::table('operational_events')->where('aggregate_type', 'trip')->whereIn('aggregate_id', $tripIds)->delete();
        DB::table('trips')->whereIn('id', $tripIds)->delete();
    }

    DB::table('freight_jobs')->whereIn('id', $jobIds ?: ['0'])->delete();
    DB::table('customers')->where('code', 'like', 'LCK%')->delete();
    DB::table('routes')->where('code', 'like', 'LCK%')->delete();
    DB::table('drivers')->whereIn('id', $driverIds ?: [0])->delete();
    DB::table('employees')->where('employee_no', 'like', 'EMP-LCK%')->delete();
    DB::table('trucks')->whereIn('id', $truckIds ?: [0])->delete();
    DB::table('trailers')->where('registration_no', 'like', '%LCK')->delete();

    foreach (['job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no']] as $scope => [$table, $column]) {
        $highest = DB::table($table)->where('company_id', 1)->pluck($column)
            ->map(static function (string $number): int {
                $dash = strpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })->max() ?? 0;

        DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
    }

    DB::statement('set session innodb_lock_wait_timeout = 50');
}

Auth::login(User::query()->findOrFail(1));
CurrentCompany::force(1);

config(['database.connections.lockprobe' => config('database.connections.mysql')]);

cleanUp();

$sql = [];
DB::listen(static function ($query) use (&$sql): void {
    $sql[] = strtolower($query->sql);
});

$probe = DB::connection('lockprobe');

try {
    echo "== the fixture: one planned trip, one truck, papers in order ==\n";

    $base = CurrentCompany::model()->base_currency_code;
    $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'LCK Probe Customer', code: 'LCK-CUST', paymentTerms: 'net_30'));
    $route = (app(CreateRoute::class))(new RouteDto(code: 'LCK-GBE-WDH', origin: 'Gaborone', destination: 'Windhoek', originCountryCode: 'BW', destinationCountryCode: 'NA', distanceKm: '750'));

    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id,
        routeId: $route->id,
        commodity: 'probe cargo',
        tonnes: 24.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
        notes: 'LCK probe job',
    ), 1);

    $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '90000', currencyCode: $base), 1);
    $job = (app(AcceptJob::class))($job, 1);

    $truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 990 LCK', fleetNo: 'T-LCK1', vin: 'VINLCK000000000000001', make: 'Probe', model: 'Lock', odometerKm: 10_000));
    $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 990 LCK', type: 'flatbed', capacityTonnes: 30.0));
    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-LCK001', name: 'LCK Probe Driver', hiredOn: '2024-01-10', nationalId: 'ID-LCK000001'));
    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id,
        licenceNo: 'DL-LCK0001',
        licenceCode: 'EC',
        licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        allowanceRateBase: 30_000,
        kioskEnabled: true,
    ));

    $trip = (app(CreateTrip::class))($job, 1);
    $trip = (app(PlanTrip::class))($trip, new TripDto(
        jobId: $job->id,
        truckId: $truck->id,
        driverId: $driver->id,
        trailerId: $trailer->id,
        plannedDepartureAt: Carbon::now()->addDays(2)->toDateTimeString(),
    ), 1);

    check($trip->status === TripStatus::Planned, 'the probe reaches the state a dispatcher dispatches from');

    /* ------------------------------------------------------------------ A: emitted */
    echo "\n== A: the lock is really in the SQL, on the rows that decide the answer ==\n";

    $sql = [];
    $dispatched = (app(DispatchTrip::class))($trip, 1);

    $lockedTrips = array_filter($sql, static fn (string $s): bool => str_contains($s, 'from `trips`') && str_contains($s, 'for update'));
    $lockedTrucks = array_filter($sql, static fn (string $s): bool => str_contains($s, 'from `trucks`') && str_contains($s, 'for update'));
    $lockedWindows = array_filter($sql, static fn (string $s): bool => str_contains($s, 'from `vehicle_assignments`') && str_contains($s, 'for update'));

    check($lockedTrips !== [], 'dispatch reads the trip row FOR UPDATE ('.count($lockedTrips).' statement(s))');
    check($lockedTrucks !== [], '...and the truck row FOR UPDATE, so a workshop move and a dispatch cannot both win');
    check($lockedWindows !== [], '...and the overlap scan FOR UPDATE, so two snapshots cannot both call the truck free');
    check($dispatched->status === TripStatus::Assigned, '...and the trip still dispatches when nothing is racing it');

    /* ------------------------------------------------------------------ B: waited on */
    echo "\n== B: a second session holding the trip row makes the dispatcher wait, not guess ==\n";

    $stale = Trip::query()->findOrFail($trip->id);
    $assignedTripId = $trip->id;

    // Release what A committed, so the row is `planned` again for the race.
    DB::table('vehicle_assignments')->where('trip_id', $assignedTripId)->delete();
    DB::table('trips')->where('id', $assignedTripId)->update(['status' => TripStatus::Planned->value]);
    DB::table('trucks')->where('id', $truck->id)->update(['status' => 'available']);
    $stale = Trip::query()->findOrFail($assignedTripId);

    $probe->beginTransaction();
    $probe->select('select * from `trips` where `id` = ? for update', [$assignedTripId]);

    DB::statement('set session innodb_lock_wait_timeout = 2');

    $waited = false;
    $message = '';

    try {
        (app(DispatchTrip::class))($stale, 1);
    } catch (DeadlockException|QueryException $blocked) {
        // 1205 arrives as `DeadlockException` (it extends PDOException, not QueryException), so catching
        // the QueryException alone would read the wait as "the probe crashed" rather than as the proof.
        $message = $blocked->getMessage();
        $waited = str_contains($message, '1205') || str_contains($message, 'Lock wait timeout');
    }

    check($waited, 'the second dispatcher is held by the lock instead of dispatching over it', mb_substr($message, 0, 160));

    $probe->rollBack();

    /* ------------------------------------------------------------------ C: decides */
    echo "\n== C: after the other session commits, the answer is the committed state ==\n";

    // The holder wins: it moved the trip. The caller still carries the model it loaded before that.
    DB::table('trips')->where('id', $assignedTripId)->update(['status' => TripStatus::Assigned->value]);
    $beforeWindows = DB::table('vehicle_assignments')->where('trip_id', $assignedTripId)->count();

    $refusal = '';

    try {
        (app(DispatchTrip::class))($stale, 1);
        failed('a trip that has been dispatched elsewhere is refused', 'the stale dispatch succeeded');
    } catch (Throwable $refused) {
        $refusal = $refused->getMessage();
        check(
            str_contains($refusal, TripStatus::Assigned->label()),
            '...and the refusal names the state the row is in now, not the state the screen last read',
            $refusal,
        );
    }

    check(
        DB::table('vehicle_assignments')->where('trip_id', $assignedTripId)->count() === $beforeWindows,
        'the refused dispatch reserved nothing — one truck, one window (§10.1)',
    );

    /* ------------------------------------------------------------------ D: the truck */
    echo "\n== D: a different trip on the same truck waits on the truck, not on its own row ==\n";

    DB::table('trips')->where('id', $assignedTripId)->update(['status' => TripStatus::Planned->value]);

    $probe->beginTransaction();
    $probe->select('select * from `trucks` where `id` = ? for update', [$truck->id]);

    DB::statement('set session innodb_lock_wait_timeout = 2');

    $blockedOnTruck = false;

    try {
        (app(DispatchTrip::class))(Trip::query()->findOrFail($assignedTripId), 1);
    } catch (DeadlockException|QueryException $blocked) {
        $blockedOnTruck = str_contains($blocked->getMessage(), '1205') || str_contains($blocked->getMessage(), 'Lock wait timeout');
    }

    check($blockedOnTruck, 'the fleet row is taken before the window is written, so the workshop cannot slip a status change underneath it');

    $probe->rollBack();

    /* ------------------------------------------------------------------ E: the phantom */
    echo "\n== E: the case InnoDB's write locks do not cover — two trips, one truck ==\n";

    // B, C and D all serialise on a row *someone is writing*, which MySQL guards whether or not the
    // Action asks. This is the case that needs the locking read: two different trips, so two different
    // rows, meeting only in the overlap query — and a consistent snapshot read answers it from the past.
    DB::table('vehicle_assignments')->where('truck_id', $truck->id)->delete();
    DB::table('trips')->where('id', $assignedTripId)->update(['status' => TripStatus::Planned->value]);

    DB::beginTransaction();
    DB::table('vehicle_assignments')->where('truck_id', $truck->id)->get();

    // The other tab books the same truck for another trip and commits — after this read opened its view.
    $window = Carbon::now()->addDays(2)->startOfDay();
    $probe->table('vehicle_assignments')->insert([
        'company_id' => 1,
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'trip_id' => '01M66M8F88KYFHBYRMD00QQ1ZS',
        'from_at' => $window->toDateTimeString(),
        'reason' => 'LCK phantom window',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bookedTwice = false;
    $refusal = '';

    try {
        (app(AssignVehicle::class))(new AssignmentDto(
            truckId: $truck->id,
            fromAt: $window->toDateTimeString(),
            trailerId: $trailer->id,
            driverId: $driver->id,
            reason: 'LCK second booking of one truck',
            tripId: $assignedTripId,
        ));
        $bookedTwice = true;
    } catch (AssignmentRejected $occupied) {
        $refusal = $occupied->getMessage();
    }

    DB::rollBack();

    check(
        ! $bookedTwice,
        'a truck booked after the dispatcher opened its read is refused, not double-booked (§10.1)',
        $bookedTwice ? 'the phantom window was invisible and the second row went in' : $refusal,
    );
} catch (Throwable $blewUp) {
    failed('the probe ran to the end', get_class($blewUp).': '.$blewUp->getMessage());

    if ($probe->getPdo() !== null && $probe->transactionLevel() > 0) {
        $probe->rollBack();
    }
} finally {
    cleanUp();
    DB::purge('lockprobe');
}

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
