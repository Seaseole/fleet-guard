<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\ChargeableBasis;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\ChargeableType;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\RouteRateType;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Phase 4's first acceptance test: **two concurrent issuers get distinct sequential numbers**.
 *
 * `verify-invoicing.php` proves one issuer consumes one value and a rollback un-burns it; that is not a
 * concurrency proof. This file holds the invoice sequence row **on a second connection** for the same MySQL
 * database, asks the real `IssueInvoice` to run while it is held, and reads what happened:
 *
 *  - the second issuer *waits* on the row rather than allocating past it (the lock is really emitted and
 *    really waited on);
 *  - once the holder commits, the second issuer takes the **next** number, so the two documents are distinct
 *    and consecutive — not the same number handed out twice;
 *  - the blocked attempt wrote nothing, so a lock wait that ends in a timeout does not leave a document or a
 *    burned number behind.
 *
 * Run: php scripts/verify-invoice-numbering.php
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

function cleanUp(): void
{
    if (! Schema::hasTable('invoices')) {
        return;
    }

    $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'IVN-CONC%')->pluck('id')->all();
    $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();
    $invoiceIds = $jobIds === [] ? [] : DB::table('invoices')->whereIn('job_id', $jobIds)->pluck('id')->all();
    $chargeableIds = $tripIds === [] ? [] : DB::table('chargeables')->whereIn('trip_id', $tripIds)->pluck('id')->all();
    $entryIds = $invoiceIds === [] ? [] : DB::table('gl_journal_entries')
        ->whereIn('source_type', ['invoice', 'invoice_void'])->whereIn('source_id', $invoiceIds)->pluck('id')->all();

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-invoice-numbering: remove this run\'s entries', null, function () use ($entryIds): void {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        });
    }

    if ($invoiceIds !== []) {
        DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('operational_events')->where('aggregate_type', 'invoice')->whereIn('aggregate_id', $invoiceIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
    }

    if ($chargeableIds !== []) {
        DB::table('chargeables')->whereIn('id', $chargeableIds)->delete();
    }

    if ($tripIds !== []) {
        DB::table('operational_events')->where('aggregate_type', 'trip')->whereIn('aggregate_id', $tripIds)->delete();
        DB::table('vehicle_assignments')->whereIn('trip_id', $tripIds)->delete();
        DB::table('trips')->whereIn('id', $tripIds)->delete();
    }

    if ($jobIds !== []) {
        DB::table('operational_events')->where('aggregate_type', 'job')->whereIn('aggregate_id', $jobIds)->delete();
        DB::table('freight_jobs')->whereIn('id', $jobIds)->delete();
    }

    DB::table('customers')->where('code', 'like', 'IVN-CONC%')->delete();
    DB::table('routes')->where('code', 'like', 'IVN-CONC%')->delete();

    $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-CONC%')->pluck('id')->all();
    if ($employeeIds !== []) {
        DB::table('drivers')->whereIn('employee_id', $employeeIds)->delete();
        DB::table('employees')->whereIn('id', $employeeIds)->delete();
    }

    DB::table('trucks')->where('registration_no', 'like', '%CN1')->delete();
    DB::table('trailers')->where('registration_no', 'like', '%CN1')->delete();

    foreach (['invoice' => ['invoices', 'number'], 'job' => ['freight_jobs', 'job_no'], 'trip' => ['trips', 'trip_no'], 'journal_entry' => ['gl_journal_entries', 'entry_no']] as $scope => [$table, $column]) {
        $highest = DB::table($table)->where('company_id', 1)->whereNotNull($column)->pluck($column)
            ->map(static function (string $number): int {
                $dash = strrpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })->max() ?? 0;

        DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
    }

    $probeIds = DB::table('users')->where('email', 'like', 'verify-ivn-%@fleetguard.local')->pluck('id')->all();
    DB::table('number_sequences')->whereIn('last_used_by', $probeIds ?: [0])->update(['last_used_by' => null]);
    DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
    DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();
}

CurrentCompany::force(1);
cleanUp();

/** @var User $issuer */
$issuer = User::query()->firstOrNew(['email' => 'verify-ivn-issuer@fleetguard.local']);
$issuer->name = 'Verify IVN Issuer';
$issuer->company_id = 1;
$issuer->password = Str::random(32);
$issuer->email_verified_at = Carbon::now();
$issuer->save();
app(ChangeUserRole::class)($issuer->fresh(), RoleKey::Accountant);
$issuer = $issuer->fresh();
Auth::login($issuer);

config(['database.connections.numberprobe' => config('database.connections.mysql')]);
DB::purge('numberprobe');

try {
    echo "== the fixture: one job, two trips, two ready charges ==\n";

    $base = CurrentCompany::model()->base_currency_code;
    $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'IVN-CONC Customer', code: 'IVN-CONC-CUST', paymentTerms: 'net_30'));
    $route = (app(CreateRoute::class))(new RouteDto(
        code: 'IVN-CONC-GBE-WDH', origin: 'Gaborone', destination: 'Windhoek',
        originCountryCode: 'BW', destinationCountryCode: 'NA', distanceKm: '750',
    ));

    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id, routeId: $route->id, commodity: 'concurrency cargo', tonnes: 20.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
        notes: 'IVN-CONC job',
    ), 1);

    $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '5000', currencyCode: $base), 1);
    $job = (app(AcceptJob::class))($job, 1);

    $truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 992 CN1', fleetNo: 'T-CN1', vin: 'VINCN1000000000000001', make: 'Probe', model: 'Number', odometerKm: 5_000));
    $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 992 CN1', type: 'flatbed', capacityTonnes: 30.0));
    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-CONC01', name: 'IVN-CONC Driver', hiredOn: '2024-01-10', nationalId: 'ID-CONC0001'));
    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id, licenceNo: 'DL-CONC001', licenceCode: 'EC',
        licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        allowanceRateBase: 30_000, kioskEnabled: true,
    ));

    $trips = [];

    foreach ([1, 2] as $index) {
        $trip = (app(CreateTrip::class))($job, 1);
        $trip = (app(PlanTrip::class))($trip, new TripDto(
            jobId: $job->id,
            truckId: $truck->id,
            driverId: $driver->id,
            trailerId: $trailer->id,
            plannedDepartureAt: Carbon::now()->addDays(2 + $index)->toDateTimeString(),
        ), 1);

        Chargeable::create([
            'id' => (string) Str::ulid(),
            'trip_id' => $trip->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => ChargeableType::Freight->value,
            'basis' => ChargeableBasis::PerTrip->value,
            'description' => 'IVN-CONC leg '.$index,
            'currency_code' => $base,
            'amount' => 500_000,
            'base_amount' => 500_000,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => 'verify-ivn:charge-'.$index,
        ]);

        $trips[] = $trip;
    }

    check(count($trips) === 2, 'two trips, two chargeable deliveries, both ready');

    /* ------------------------------------------------------------------ A: it waits */
    echo "\n== A: while one issuer holds the sequence, the other waits instead of allocating past it ==\n";

    $originalConnection = config('database.default');
    $heldNumber = null;
    $blocked = false;
    $blockMessage = '';

    DB::beginTransaction();

    try {
        // The holder issues inside an outer transaction, so its sequence row lock is still held when the
        // second session asks for a number.
        $held = (app(IssueInvoice::class))($trips[0]->id, $issuer->id);
        $heldNumber = $held->number;

        check($heldNumber !== null, 'the holding issuer took a number while inside the open transaction', (string) $heldNumber);

        config(['database.default' => 'numberprobe']);
        DB::purge('numberprobe');

        $probe = DB::connection('numberprobe');
        $probe->statement('set session innodb_lock_wait_timeout = 2');

        try {
            (app(IssueInvoice::class))($trips[1]->id, $issuer->id);
        } catch (QueryException $wait) {
            $blockMessage = $wait->getMessage();
            $blocked = str_contains($blockMessage, '1205') || str_contains($blockMessage, 'Lock wait timeout');
        }

        config(['database.default' => $originalConnection]);
        DB::purge('numberprobe');
    } finally {
        // The outer commit releases the sequence row whether or not the probe behaved.
        DB::commit();
        config(['database.default' => $originalConnection]);
    }

    check($blocked, 'the second issuer is held by the sequence row, not handed a number alongside it', mb_substr($blockMessage, 0, 160));
    check(Invoice::query()->where('job_id', $job->id)->count() === 1, '...and the blocked attempt wrote no document at all');

    /* ------------------------------------------------------------------ B: it takes the next */
    echo "\n== B: after the holder commits, the second issuer takes the next number ==\n";

    $second = (app(IssueInvoice::class))($trips[1]->id, $issuer->id);

    check($second->number !== null, 'the second issuer completes once the row is free', (string) $second->number);
    check($second->id !== Invoice::query()->where('job_id', $job->id)->where('number', $heldNumber)->value('id'), 'the two documents are different rows');
    check($second->number !== $heldNumber, 'and carry different numbers (definition of done 1)', "{$heldNumber} vs {$second->number}");

    $sequence = static function (string $number): int {
        return (int) preg_replace('/\D/', '', substr($number, (int) strrpos($number, '-')));
    };

    check(
        $sequence((string) $second->number) === $sequence((string) $heldNumber) + 1,
        'the numbers are consecutive, so the lock serialised rather than skipping',
        $heldNumber.' then '.$second->number,
    );

    $numbers = Invoice::query()->where('job_id', $job->id)->pluck('number')->sort()->values()->all();
    check(count($numbers) === count(array_unique($numbers)), 'no number was handed out twice');
} catch (Throwable $blewUp) {
    failed('the probe ran to the end', get_class($blewUp).': '.$blewUp->getMessage());

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
} finally {
    config(['database.default' => config('database.default')]);
    cleanUp();
    DB::purge('numberprobe');
}

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
