<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\AdvanceTrip;
use App\Domain\Operations\Actions\ApprovePod;
use App\Domain\Operations\Actions\BuildChargeablesFromPod;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\DepartTrip;
use App\Domain\Operations\Actions\DispatchTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Actions\RecordTripCost;
use App\Domain\Operations\ChargeableBasis;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripCostDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\ProofOfDelivery;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Operations\PodStatus;
use App\Domain\Operations\RouteRateType;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\Security\Actions\ChangeUserRole;
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
 * Phase 2 slice 2e verification — trip costs, the ledger rollup and chargeables (DESIGN.md §6.6, §16).
 *
 * Not the test suite (`tests/` is local-only, §14.6). This is the runnable proof of the sentences the
 * slice turns on:
 *
 * - §6.6 *"the trip carries its own direct costs"* — every cost type posts a balanced entry from its own
 *   account to the account the money left from, the bucket sum equals the ledger, and an advance posts
 *   to a receivable without joining any bucket;
 * - §5.2's FX invariant — a USD slip's base figure is the authoritative one, computed from the stated
 *   rate and frozen with it;
 * - §6.6's derived projection — `trips:recost` notices a stored rollup that no longer describes the
 *   lines, and `--fix` repairs it from them;
 * - §16's chargeable — an approved POD earns what the job was quoted (per trip, per tonne), once, and
 *   a per-km job earns nothing from a POD before Phase 4's billing.
 *
 * It writes to the development database on the live MySQL 9.1 instance (docs/DEV-DATABASE.md) inside the
 * `CST` fixture namespace, lifts the §6.3 barrier only to delete its own journal entries, and asserts that
 * everything it created is gone and the barrier is down again.
 *
 * Run: php scripts/verify-costs.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/** Delete rows by an id list, refusing to run on an empty list (§20.14's integer/ULID comparison). */
function wipeIds(string $table, string $column, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    return DB::table($table)->whereIn($column, $ids)->delete();
}

/** @return list<string> */
function costJournalEntries(array $costIds): array
{
    if ($costIds === []) {
        return [];
    }

    return DB::table('gl_journal_entries')
        ->where('source_type', 'trip_cost')
        ->whereIn('source_id', $costIds)
        ->pluck('id')
        ->all();
}

/** @param list<string> $entryIds */
function purgeJournalEntries(array $entryIds, string $why): void
{
    if ($entryIds === []) {
        return;
    }

    LedgerBarrier::withLiftedGuard($why, null, function () use ($entryIds): void {
        DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
    });
}

/*
 * Pre-clean: a run that died halfway leaves costs, chargeables, PODs and — because §6.3 refuses the
 * delete — journal entries the next run would count as fixtures. Everything this file writes is inside
 * the `CST` namespace so the sweep is exact.
 */
$cstJobIds = DB::table('freight_jobs')->where('notes', 'like', 'CST%')->pluck('id')->all();
$cstTripIds = $cstJobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $cstJobIds)->pluck('id')->all();
$cstCostIds = $cstTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $cstTripIds)->pluck('id')->all();
$cstPodIds = $cstTripIds === [] ? [] : DB::table('proof_of_deliveries')->whereIn('trip_id', $cstTripIds)->pluck('id')->all();
$cstDocIds = DB::table('documents')->where('title', 'like', 'CST%')->pluck('id')->all();
$cstTruckIds = DB::table('trucks')->where('registration_no', 'like', '%CST')->pluck('id')->all();
$cstEmployeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-CST%')->pluck('id')->all();
$cstDriverIds = $cstEmployeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $cstEmployeeIds)->pluck('id')->all();

purgeJournalEntries(costJournalEntries($cstCostIds), 'verify-costs: clear an interrupted run\'s entries');

wipeIds('chargeables', 'trip_id', $cstTripIds);
wipeIds('proof_of_deliveries', 'trip_id', $cstTripIds);
wipeIds('trip_costs', 'trip_id', $cstTripIds);
wipeIds('document_versions', 'document_id', $cstDocIds);
wipeIds('documents', 'id', $cstDocIds);
wipeIds('loads', 'trip_id', $cstTripIds);
wipeIds('odometer_readings', 'trip_id', $cstTripIds);
wipeIds('odometer_readings', 'truck_id', $cstTruckIds);
wipeIds('vehicle_assignments', 'trip_id', $cstTripIds);
wipeIds('operational_events', 'aggregate_id', $cstTripIds);
wipeIds('operational_events', 'aggregate_id', $cstJobIds);
wipeIds('trips', 'id', $cstTripIds);
wipeIds('freight_jobs', 'id', $cstJobIds);
wipeIds('drivers', 'id', $cstDriverIds);
wipeIds('employees', 'id', $cstEmployeeIds);
wipeIds('trucks', 'id', $cstTruckIds);
DB::table('trailers')->where('registration_no', 'like', '%CST')->delete();
DB::table('routes')->where('code', 'like', 'CST%')->delete();
DB::table('customers')->where('code', 'like', 'CST%')->delete();
DB::table('companies')->where('name', 'Verify CST Second Company')->delete();

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

function asCompanyOne(): void
{
    CurrentCompany::force(1);
}

$runStartedAt = Carbon::now();
$owner = User::query()->findOrFail(1);
Auth::login($owner);
CurrentCompany::force(1);

$created = [
    'truck_ids' => [], 'trailer_ids' => [], 'employee_ids' => [], 'driver_ids' => [],
    'job_ids' => [], 'trip_ids' => [], 'pod_ids' => [], 'document_ids' => [],
    'cost_ids' => [], 'chargeable_ids' => [],
];

/* ------------------------------------------------------------------------- */
echo "== §4.1/§6.6 the schema keeps the promises the docs make (live MySQL 9.1) ==\n";

check(
    Schema::hasColumns('trips', [
        'revenue_base', 'fuel_cost_base', 'maintenance_cost_base', 'border_cost_base',
        'driver_cost_base', 'other_direct_cost_base', 'direct_cost_base', 'contribution_base',
        'ledger_posted_at',
    ]),
    'trips carries the nine §6.6 rollup columns'
);

$tripDdl = (string) ((array) DB::select('show create table trips')[0])['Create Table'];

foreach (['trips_rollup_direct_sums', 'trips_rollup_contribution', 'trips_frozen_is_closed'] as $name) {
    check(str_contains($tripDdl, $name), "trips carries {$name}");
}

$costDdl = (string) ((array) DB::select('show create table trip_costs')[0])['Create Table'];

foreach ([
    'trip_costs_trip_company_fk',
    'trip_costs_source_unique',
    'trip_costs_type_allowed',
    'trip_costs_paid_with_allowed',
    'trip_costs_amount_positive',
    'trip_costs_rate_positive',
    'trip_costs_currency_upper',
    'trip_costs_source_allowed',
] as $name) {
    check(str_contains($costDdl, $name), "trip_costs carries {$name}");
}

$chargeableDdl = (string) ((array) DB::select('show create table chargeables')[0])['Create Table'];

foreach ([
    'chargeables_source_unique',
    'chargeables_trip_company_fk',
    'chargeables_job_company_fk',
    'chargeables_type_allowed',
    'chargeables_basis_allowed',
    'chargeables_status_allowed',
    'chargeables_amount_positive',
] as $name) {
    check(str_contains($chargeableDdl, $name), "chargeables carries {$name}");
}

/* ------------------------------------------------------------------------- */
echo "\n== fixtures: a planned trip walked to arrived ==\n";

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'CST Verify Customer', code: 'CST-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'CST-GBE-WDH',
    origin: 'Gaborone',
    destination: 'Windhoek',
    originCountryCode: 'BW',
    destinationCountryCode: 'NA',
    distanceKm: '750',
));

$baseCurrency = CurrentCompany::model()->base_currency_code;

$truck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 82 CST',
    fleetNo: 'T-CST1',
    vin: 'VINCST000000000000001',
    make: 'Probe',
    model: 'Cost',
    odometerKm: 300_000,
));
$created['truck_ids'][] = $truck->id;

$trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 82 CST', type: 'flatbed', capacityTonnes: 30.0));
$created['trailer_ids'][] = $trailer->id;

$employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-CST001', name: 'CST Verify Driver', hiredOn: '2024-01-10'));
$created['employee_ids'][] = $employee->id;

$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-CST001',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $driver->id;

$job = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'packaged goods',
    tonnes: 28.0,
    requiredLoadOn: Carbon::now()->addDays(3)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(9)->toDateString(),
    notes: 'CST main job',
));
$created['job_ids'][] = $job->id;

$job = (app(QuoteJob::class))($job, new JobQuoteDto(
    rateType: RouteRateType::PerTrip,
    amountMajor: '1200.00',
    currencyCode: $baseCurrency,
), 1);
$job = (app(AcceptJob::class))($job, 1);

$trip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $trip->id;

$trip = (app(PlanTrip::class))($trip, new TripDto(
    jobId: $job->id,
    truckId: $truck->id,
    driverId: $driver->id,
    trailerId: $trailer->id,
    plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
), 1);
$trip = (app(DispatchTrip::class))($trip, 1);
$trip = (app(DepartTrip::class))($trip, 300_001, null, 1);
$trip = (app(AdvanceTrip::class))($trip, TripStatus::InTransit, 1);
$trip = (app(AdvanceTrip::class))($trip, TripStatus::Arrived, 1);

check($trip->status === TripStatus::Arrived, 'the fixture trip is arrived ('.$trip->trip_no.')');

/* ------------------------------------------------------------------------- */
echo "\n== §6.6 recording costs: one balanced entry per cost, from the type's own account ==\n";

$record = app(RecordTripCost::class);

$dto = static function (
    TripCostType $type,
    Money $amount,
    string $rate = '1',
    ?TripCostPayment $paid = null,
    ?string $description = null,
    ?string $sourceType = null,
    ?string $sourceId = null,
): TripCostDto {
    $on = Carbon::now(CurrentCompany::timezone())->startOfDay();

    return new TripCostDto(
        type: $type,
        amount: $amount,
        rate: $rate,
        rateSource: FxRateSource::Manual,
        rateDate: $on,
        occurredOn: $on,
        paidWith: $paid ?? TripCostPayment::PettyCash,
        description: $description,
        sourceType: $sourceType,
        sourceId: $sourceId,
    );
};

$accounts = DB::table('gl_accounts')->where('company_id', 1)->pluck('id', 'code');

/** @return array{entry: object, lines: array<string, object>} */
$postingFor = static function (TripCost $cost): array {
    $entry = DB::table('gl_journal_entries')
        ->where('source_type', 'trip_cost')
        ->where('source_id', $cost->id)
        ->first();

    if ($entry === null) {
        throw new RuntimeException('No journal entry for trip cost '.$cost->id);
    }

    $lines = DB::table('gl_journal_lines as l')
        ->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.entry_id', $entry->id)
        ->get(['l.*', 'a.code as account_code'])
        ->keyBy('direction')
        ->all();

    return ['entry' => $entry, 'lines' => $lines];
};

$assertEntry = static function (TripCost $cost, TripCostType $type, int $expectedBase, ?int $expectedForeign = null) use ($postingFor, $trip, $truck): void {
    ['entry' => $entry, 'lines' => $lines] = $postingFor($cost);

    check((int) $entry->total_debit === $expectedBase && (int) $entry->total_credit === $expectedBase, 'the entry for the '.$type->value.' cost balances at '.$expectedBase.' minor units');
    check((int) $entry->line_count === 2, '...and carries exactly two lines');

    $debit = $lines['debit'] ?? null;
    $credit = $lines['credit'] ?? null;

    check($debit !== null && $debit->account_code === $type->accountCode(), 'the '.$type->value.' cost debits account '.$type->accountCode());
    check($credit !== null && $credit->account_code === $cost->paid_with->accountCode(), '...and credits the '.$cost->paid_with->value.' account '.$cost->paid_with->accountCode());
    check($debit !== null && (int) $debit->amount_base === $expectedBase, '...at the base figure the cost row carries');
    check($debit !== null && $debit->trip_id === $trip->id, '...with the trip dimension §6.1 makes mandatory on every direct-cost account');

    if ($expectedForeign !== null) {
        check($debit !== null && (int) $debit->amount_foreign === $expectedForeign, '...and the foreign figure on the same line ('.$expectedForeign.' minor)');
    }

    if ($type === TripCostType::Fuel) {
        check($debit !== null && (int) $debit->truck_id === (int) $truck->id, 'the fuel line names the truck as well as the trip');
    }

    if ($type->requiresDriver()) {
        check($debit !== null && (int) $debit->driver_id === (int) $cost->trip->driver_id, 'the '.$type->value.' line names the driver');
    }

    check($credit !== null && $credit->trip_id === $trip->id, 'and the credit line names the trip, so cash movement is attributable too');
};

$fuel = $record($trip, $dto(TripCostType::Fuel, Money::ofMajor('1000.00', 'USD'), '13.50', TripCostPayment::Bank, 'Gaborone filling station'));
$created['cost_ids'][] = $fuel->id;

check($fuel->currency_code === 'USD' && $fuel->amount === 100_000, 'the USD slip keeps its foreign figure (100 000 minor)');
check($fuel->base_amount === 1_350_000, '...and the base figure is 100 000 × 13.50 = 1 350 000 minor (§5.2)');
check($fuel->fx_rate_source === FxRateSource::Manual && (float) $fuel->fx_rate === 13.5, '...with the rate and its source frozen on the row');
check($fuel->paid_with === TripCostPayment::Bank, 'the row says the money left the bank');
$assertEntry($fuel, TripCostType::Fuel, 1_350_000, 100_000);

$parts = $record($trip, $dto(TripCostType::Parts, Money::ofMajor('750.00', $baseCurrency), '1', TripCostPayment::SupplierCredit, 'Replacement brake pads'));
$created['cost_ids'][] = $parts->id;
$assertEntry($parts, TripCostType::Parts, 75_000);

$borderSource = (string) Str::ulid();
$border = $record($trip, $dto(TripCostType::Border, Money::ofMajor('450.00', $baseCurrency), '1', TripCostPayment::SupplierCredit, 'Kazungula bridge toll', 'border_crossing', $borderSource));
$created['cost_ids'][] = $border->id;
$assertEntry($border, TripCostType::Border, 45_000);

$borderRetry = $record($trip, $dto(TripCostType::Border, Money::ofMajor('450.00', $baseCurrency), '1', TripCostPayment::SupplierCredit, 'Kazungula bridge toll', 'border_crossing', $borderSource));

check($borderRetry->id === $border->id, 'a retried capture with the same source lands on the same cost row (§6.6 idempotency)');
check((int) DB::table('trip_costs')->where('source_type', 'border_crossing')->where('source_id', $borderSource)->count() === 1, '...and the table holds exactly one row for it');
check((int) DB::table('gl_journal_entries')->where('source_type', 'trip_cost')->where('source_id', $border->id)->count() === 1, '...with one journal entry, not two');

$labour = $record($trip, $dto(TripCostType::Labour, Money::ofMajor('900.00', $baseCurrency), '1', TripCostPayment::PettyCash, 'Two days at the wheel'));
$created['cost_ids'][] = $labour->id;
$assertEntry($labour, TripCostType::Labour, 90_000);

$lodging = $record($trip, $dto(TripCostType::Lodging, Money::ofMajor('600.00', $baseCurrency), '1', TripCostPayment::PettyCash, 'Stopover'));
$created['cost_ids'][] = $lodging->id;
$assertEntry($lodging, TripCostType::Lodging, 60_000);

$toll = $record($trip, $dto(TripCostType::Toll, Money::ofMajor('120.00', $baseCurrency), '1', TripCostPayment::PettyCash, 'Plumtree road toll'));
$created['cost_ids'][] = $toll->id;
$assertEntry($toll, TripCostType::Toll, 12_000);

$other = $record($trip, $dto(TripCostType::Other, Money::ofMajor('230.00', $baseCurrency), '1', TripCostPayment::PettyCash, 'Wash and weighbridge'));
$created['cost_ids'][] = $other->id;
$assertEntry($other, TripCostType::Other, 23_000);

$advance = $record($trip, $dto(TripCostType::Advance, Money::ofMajor('2000.00', $baseCurrency), '1', TripCostPayment::PettyCash, 'Cash for the road'));
$created['cost_ids'][] = $advance->id;

check($advance->cost_type === TripCostType::Advance, 'the advance is recorded as an advance');
check((int) DB::table('gl_journal_lines')->where('entry_id', $postingFor($advance)['entry']->id)->where('account_id', (int) $accounts['1160'])->count() === 1, '...posting to the driver-receivable account 1160, not to an expense');

/* ------------------------------------------------------------------------- */
echo "\n== §6.6 the rollup: buckets that equal the ledger, and no revenue invented ==\n";

$fresh = Trip::query()->findOrFail($trip->id);

check($fresh->fuel_cost_base === 1_350_000, 'fuel bucket = the USD fuel cost at its base figure');
check($fresh->maintenance_cost_base === 75_000, 'maintenance bucket = parts');
check($fresh->border_cost_base === 45_000, 'border bucket = the border cost, counted once despite the retry');
check($fresh->driver_cost_base === 150_000, 'driver bucket = labour + lodging');
check($fresh->other_direct_cost_base === 35_000, 'other bucket = toll + other');
check($fresh->direct_cost_base === 1_655_000, 'direct costs = the sum of the five buckets');
check($fresh->revenue_base === 0, 'revenue is zero: a chargeable is not revenue until Phase 4 bills it (§20.17)');
check($fresh->contribution_base === -1_655_000, 'contribution = revenue − direct costs');
check($fresh->ledger_posted_at !== null, 'the projection records that it has read the ledger');

$projection = $fresh->direct_cost_base
    - ($fresh->fuel_cost_base + $fresh->maintenance_cost_base + $fresh->border_cost_base + $fresh->driver_cost_base + $fresh->other_direct_cost_base);

check($projection === 0, 'the stored direct total equals its own five bucket columns (the §6.6 CHECK, read back)');
check(
    $fresh->direct_cost_base === (int) DB::table('trip_costs')->where('trip_id', $trip->id)->where('cost_type', '!=', 'advance')->sum('base_amount'),
    '...and it equals the expense rows only: the 2 000.00 advance never joined a bucket'
);

/* ------------------------------------------------------------------------- */
echo "\n== §6.6 refusals: the Action says why before the ledger has to ==\n";

refuses('a zero cost is refused', fn () => $record($trip, $dto(TripCostType::Fuel, Money::ofMinor(0, $baseCurrency))), InvalidArgumentException::class, 'positive');
refuses('a negative cost is refused', fn () => $record($trip, $dto(TripCostType::Fuel, Money::ofMinor(-100, $baseCurrency))), InvalidArgumentException::class, 'positive');
refuses(
    'a 161-character description is refused',
    fn () => $record($trip, $dto(TripCostType::Fuel, Money::ofMajor('10.00', $baseCurrency), '1', null, str_repeat('x', 161))),
    InvalidArgumentException::class,
    '160 characters'
);
refuses(
    'a USD cost whose stated rate rounds the base figure to nothing is refused',
    fn () => $record($trip, $dto(TripCostType::Fuel, Money::ofMinor(1, 'USD'), '0.001')),
    InvalidArgumentException::class,
    'less than one'
);

$draftTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $draftTrip->id;

refuses(
    'a labour cost on a trip with no driver is refused before anything is written',
    fn () => $record($draftTrip, $dto(TripCostType::Labour, Money::ofMajor('50.00', $baseCurrency))),
    InvalidArgumentException::class,
    'none named'
);
check((int) DB::table('trip_costs')->where('trip_id', $draftTrip->id)->count() === 0, '...and the trip still has no cost row');

$frozenTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $frozenTrip->id;
// The closed/cancelled states need a named crew (`trips_plan_is_named`); the refusal under test is about
// the freeze, not about a half-planned row.
$frozenTrip->forceFill([
    'status' => TripStatus::Closed->value,
    'costs_frozen' => true,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();

refuses(
    'a closed, frozen trip refuses new costs (§10.2)',
    fn () => $record($frozenTrip->fresh(), $dto(TripCostType::Toll, Money::ofMajor('10.00', $baseCurrency))),
    InvalidArgumentException::class,
    'costs are final'
);

$cancelledTrip = (app(CreateTrip::class))($job, 1);
$created['trip_ids'][] = $cancelledTrip->id;
$cancelledTrip->forceFill([
    'status' => TripStatus::Cancelled->value,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();

refuses(
    'a cancelled trip refuses new costs',
    fn () => $record($cancelledTrip->fresh(), $dto(TripCostType::Toll, Money::ofMajor('10.00', $baseCurrency))),
    InvalidArgumentException::class,
    'costs are final'
);

/* A dispatcher may quote and dispatch, but §9.3 gives no seat without `expenses.create` the pen. */
/** @var User $dispatcher */
$dispatcher = User::query()->firstOrNew(['email' => 'verify-cst-dispatcher@fleetguard.local']);
$dispatcher->name = 'Verify CST Dispatcher';
$dispatcher->company_id = $owner->company_id;
$dispatcher->password = Str::random(32);
$dispatcher->email_verified_at = Carbon::now();
$dispatcher->save();
app(ChangeUserRole::class)($dispatcher->fresh(), RoleKey::Dispatcher);
$dispatcher = $dispatcher->fresh();

check(! Gate::forUser($dispatcher)->allows('expenses.create'), 'a dispatcher does not hold expenses.create (§9.3)');
check(Gate::forUser($dispatcher)->allows('invoices.view'), '...but may see the billing state of a delivery');

Auth::login($dispatcher);
refuses(
    'a dispatcher cannot record a trip cost',
    fn () => $record($trip, $dto(TripCostType::Toll, Money::ofMajor('10.00', $baseCurrency))),
    InvalidArgumentException::class,
    'may not raise one'
);
Auth::login($owner);
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== §6.6 trips:recost reads the lines, notices drift, and repairs it ==\n";

$recost = static fn (array $options): array => [Artisan::call('trips:recost', $options), Artisan::output()];

[$exit, $output] = $recost(['--trip' => $trip->trip_no, '--company' => 1, '--quiet-ok' => true]);
check($exit === 0, 'trips:recost finds the stored rollup describing its ledger lines', mb_substr(str_replace("\n", ' ', $output), 0, 160));

$bumpedRevenue = $fresh->revenue_base + 100;
$bumpedContribution = $bumpedRevenue - $fresh->direct_cost_base;

DB::table('trips')->where('id', $trip->id)->update([
    'revenue_base' => $bumpedRevenue,
    'contribution_base' => $bumpedContribution,
]);

[$exit, $output] = $recost(['--trip' => $trip->trip_no, '--company' => 1, '--quiet-ok' => true]);
check($exit === 1, 'a stored rollup that disagrees with the lines exits 1 so the scheduler can alarm');
check(str_contains($output, 'DRIFT'), '...and names the drifting trip', mb_substr(str_replace("\n", ' ', $output), 0, 160));

[$exit, $output] = $recost(['--trip' => $trip->trip_no, '--company' => 1, '--fix' => true, '--quiet-ok' => true]);
$repaired = Trip::query()->findOrFail($trip->id);
check($exit === 0, '--fix exits 0 after repairing');
check($repaired->revenue_base === 0 && $repaired->contribution_base === -1_655_000, '...and rewrites the figure the ledger actually supports');

[$exit, $output] = $recost(['--trip' => 'TRP-NOT-A-TRIP', '--company' => 1, '--quiet-ok' => true]);
check($exit === 1 && str_contains($output, 'No trip matches'), 'a filter that matches nothing is an error, not a silent pass');

/* ------------------------------------------------------------------------- */
echo "\n== §16 approving a proof earns the job's price, once ==\n";

$trip = (app(AdvanceTrip::class))($trip->refresh(), TripStatus::PodPending, 1);

$signature = Document::forceCreate([
    'type' => 'pod',
    'title' => 'CST POD signature '.$trip->trip_no,
    'lifecycle' => Lifecycle::Current->value,
    'retention_class' => 'legal',
]);
$created['document_ids'][] = $signature->id;

$pod = ProofOfDelivery::forceCreate([
    'trip_id' => $trip->id,
    'status' => 'submitted',
    'delivered_at' => Carbon::now(),
    'receiver_name' => 'CST Receiver',
    'quantity_received_tonnes_10_2' => '27.55',
    'signature_document_id' => $signature->id,
    'exceptions' => [],
]);
$created['pod_ids'][] = $pod->id;

$approved = (app(ApprovePod::class))($pod, 1);
check($approved->status === PodStatus::Approved, 'the office approves the proof');
check($trip->refresh()->status === TripStatus::Delivered, '...and the trip moves to delivered on that approval, not on the driver\'s word');

$chargeable = Chargeable::query()->where('source_uuid', 'pod:'.$pod->id)->first();

check($chargeable !== null, 'the approval wrote a chargeable (§16)');
check($chargeable !== null && $chargeable->amount === $job->quoted_amount && $chargeable->base_amount === $job->base_amount, '...for exactly the price the job was quoted (120 000 minor)');
check($chargeable !== null && $chargeable->basis === ChargeableBasis::PerTrip, '...on the job\'s basis');
check($chargeable !== null && $chargeable->status === ChargeableStatus::Ready, '...ready to bill, which is where Phase 4 picks it up');
check($chargeable !== null && $chargeable->trip_id === $trip->id && $chargeable->job_id === $job->id, '...naming both the leg and the job');
$created['chargeable_ids'][] = $chargeable?->id ?? '';

$again = (app(BuildChargeablesFromPod::class))($pod->refresh());
check($again !== null && $again->id === $chargeable?->id, 'rebuilding from the same proof returns the same row, not a second bill');
check((int) DB::table('chargeables')->where('source_uuid', 'pod:'.$pod->id)->count() === 1, '...and the table agrees');

refuses(
    'approving the same proof twice is refused',
    fn () => (app(ApprovePod::class))($pod->refresh(), 1),
    InvalidArgumentException::class,
    'already approved'
);

/* ------------------------------------------------------------------------- */
echo "\n== §16 the basis decides the amount: per tonne multiplies, per km builds nothing ==\n";

$tonnageJob = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'cement',
    tonnes: 30.0,
    requiredLoadOn: Carbon::now()->addDays(5)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(11)->toDateString(),
    notes: 'CST tonne job',
));
$created['job_ids'][] = $tonnageJob->id;
$tonnageJob = (app(QuoteJob::class))($tonnageJob, new JobQuoteDto(rateType: RouteRateType::PerTonne, amountMajor: '350.00', currencyCode: $baseCurrency), 1);
$tonnageJob = (app(AcceptJob::class))($tonnageJob, 1);

$tonnageTrip = (app(CreateTrip::class))($tonnageJob, 1);
$created['trip_ids'][] = $tonnageTrip->id;

$tonnagePod = ProofOfDelivery::forceCreate([
    'trip_id' => $tonnageTrip->id,
    'status' => 'submitted',
    'delivered_at' => Carbon::now(),
    'receiver_name' => 'CST Weighbridge',
    'quantity_received_tonnes_10_2' => '27.55',
    'exceptions' => [],
]);
$created['pod_ids'][] = $tonnagePod->id;

$tonnageCharge = (app(BuildChargeablesFromPod::class))($tonnagePod);

check($tonnageCharge !== null, 'a per-tonne delivery earns a chargeable');
check($tonnageCharge?->amount === 964_250, '...at 350.00 × 27.55 tonnes = 964 250 minor, rounded HALF_UP (§5.5)');
check($tonnageCharge?->base_amount === 964_250, '...and the base figure matches because the quote is in the base currency');
check($tonnageCharge?->basis === ChargeableBasis::PerTonne, '...recorded on the per-tonne basis');
$created['chargeable_ids'][] = $tonnageCharge?->id ?? '';

$tonnageAgain = (app(BuildChargeablesFromPod::class))($tonnagePod->refresh());
check($tonnageAgain?->id === $tonnageCharge?->id, 'a second build from the same proof is a lookup, not a second charge');

$kmJob = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'machinery',
    tonnes: 12.0,
    requiredLoadOn: Carbon::now()->addDays(6)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(12)->toDateString(),
    notes: 'CST kilometre job',
));
$created['job_ids'][] = $kmJob->id;
$kmJob = (app(QuoteJob::class))($kmJob, new JobQuoteDto(rateType: RouteRateType::PerKm, amountMajor: '100.00', currencyCode: $baseCurrency), 1);
$kmJob = (app(AcceptJob::class))($kmJob, 1);

$kmTrip = (app(CreateTrip::class))($kmJob, 1);
$created['trip_ids'][] = $kmTrip->id;

$kmPod = ProofOfDelivery::forceCreate([
    'trip_id' => $kmTrip->id,
    'status' => 'submitted',
    'delivered_at' => Carbon::now(),
    'receiver_name' => 'CST Depot',
    'quantity_received_tonnes_10_2' => '12.00',
    'exceptions' => [],
]);
$created['pod_ids'][] = $kmPod->id;

check((app(BuildChargeablesFromPod::class))($kmPod) === null, 'a per-km job earns nothing from a POD — a proof carries tonnage, not distance (§16)');
check((int) DB::table('chargeables')->where('trip_id', $kmTrip->id)->count() === 0, '...and no chargeable row was invented for it');

/* ------------------------------------------------------------------------- */
echo "\n== §8 tenancy: the new registers are scoped, not filtered by habit ==\n";

$stamp = Carbon::now()->toDateTimeString();

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify CST Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

$runTripIds = array_values(array_unique($created['trip_ids']));

CurrentCompany::force(2);
check((int) TripCost::query()->count() === 0, 'company 2 reads no trip costs');
check((int) Chargeable::query()->count() === 0, '...and no chargeables');
check((int) Trip::query()->whereIn('id', $runTripIds)->count() === 0, '...and none of company 1\'s trips');

CurrentCompany::force(1);
check((int) TripCost::query()->count() > 0 && (int) Chargeable::query()->count() > 0, 'company 1 still reads its own rows');
DB::table('companies')->where('id', 2)->delete();
asCompanyOne();

/* ------------------------------------------------------------------------- */
echo "\n== §6.3 the ledger still verifies with these postings on it ==\n";

$ledgerExit = Artisan::call('ledger:verify', ['--quiet-ok' => true]);
$ledgerOutput = Artisan::output();
check($ledgerExit === 0, 'ledger:verify passes with the trip-cost entries in place', mb_substr(str_replace("\n", ' ', $ledgerOutput), 0, 240));

/* ------------------------------------------------------------------------- */
echo "\n== cleanup, and the proof it worked ==\n";

$runTripIds = array_values(array_unique(array_merge(
    $created['trip_ids'],
    DB::table('trips')->whereIn('job_id', $created['job_ids'] ?: ['0'])->pluck('id')->all(),
)));
$runCostIds = array_values(array_unique(array_merge(
    $created['cost_ids'],
    $runTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $runTripIds)->pluck('id')->all(),
)));
$runChargeableIds = $runTripIds === [] ? [] : DB::table('chargeables')->whereIn('trip_id', $runTripIds)->pluck('id')->all();
$runPodIds = $runTripIds === [] ? [] : DB::table('proof_of_deliveries')->whereIn('trip_id', $runTripIds)->pluck('id')->all();
$runDocIds = array_values(array_unique(array_merge(
    $created['document_ids'],
    DB::table('documents')->where('title', 'like', 'CST%')->pluck('id')->all(),
)));

/**
 * The fixture never sold a delivery, so every entry it wrote leaves with the fixture — through the §6.3
 * hatch, with a reason and a lower-again assertion, exactly as verify-ledger does it.
 */
$runEntryIds = costJournalEntries($runCostIds);
purgeJournalEntries($runEntryIds, 'verify-costs: remove this run\'s trip-cost entries');

check(! LedgerBarrier::isLifted(), 'the §6.3 barrier is down again after the cleanup');

wipeIds('chargeables', 'trip_id', $runTripIds);
wipeIds('proof_of_deliveries', 'trip_id', $runTripIds);
wipeIds('document_versions', 'document_id', $runDocIds);
wipeIds('documents', 'id', $runDocIds);
wipeIds('trip_costs', 'trip_id', $runTripIds);
wipeIds('loads', 'trip_id', $runTripIds);
wipeIds('odometer_readings', 'trip_id', $runTripIds);
wipeIds('odometer_readings', 'truck_id', $created['truck_ids']);
wipeIds('vehicle_assignments', 'trip_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $runTripIds);
wipeIds('operational_events', 'aggregate_id', $created['job_ids']);
wipeIds('trips', 'id', $runTripIds);
wipeIds('freight_jobs', 'id', $created['job_ids']);

DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $created['truck_ids'] ?: [0])->delete();
DB::table('trailers')->whereIn('id', $created['trailer_ids'] ?: [0])->delete();
DB::table('routes')->where('code', 'like', 'CST%')->delete();
DB::table('customers')->where('code', 'like', 'CST%')->delete();

foreach ([Trip::class, Job::class, TripCost::class, Chargeable::class, ProofOfDelivery::class, Document::class] as $type) {
    DB::table('audits')->where('auditable_type', $type)->where('created_at', '>=', $runStartedAt)->delete();
}

// The probe seat goes after its grant rows: `model_has_roles` carries a team id and refuses to let the
// account leave first (the same children-before-parents rule verify-master records).
$probeIds = DB::table('users')->where('email', 'like', 'verify-cst-%@fleetguard.local')->pluck('id')->all();
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

$alignCounters('after cleanup');

$afterExit = Artisan::call('documents:number-integrity');
$afterOutput = Artisan::output();
check($afterExit === 0, 'the counters and the surviving rows agree once the fixtures are gone', mb_substr(str_replace("\n", ' ', $afterOutput), 0, 200));

$leftovers = [
    'trips' => (int) DB::table('trips')->whereIn('id', $runTripIds ?: ['0'])->count(),
    'freight_jobs' => (int) DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: ['0'])->count(),
    'trip_costs' => (int) DB::table('trip_costs')->whereIn('id', $runCostIds ?: ['0'])->count(),
    'chargeables' => (int) DB::table('chargeables')->whereIn('id', $runChargeableIds ?: ['0'])->count(),
    'proofs' => (int) DB::table('proof_of_deliveries')->whereIn('id', $runPodIds ?: ['0'])->count(),
    'documents' => (int) DB::table('documents')->where('title', 'like', 'CST%')->count(),
    'journal_entries' => (int) DB::table('gl_journal_entries')->whereIn('id', $runEntryIds ?: ['0'])->count(),
    'journal_lines' => (int) DB::table('gl_journal_lines')->whereIn('entry_id', $runEntryIds ?: ['0'])->count(),
    'trucks' => (int) DB::table('trucks')->where('registration_no', 'like', '%CST')->count(),
    'trailers' => (int) DB::table('trailers')->where('registration_no', 'like', '%CST')->count(),
    'employees' => (int) DB::table('employees')->where('employee_no', 'like', 'EMP-CST%')->count(),
    'drivers' => (int) DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->count(),
    'customers' => (int) DB::table('customers')->where('code', 'like', 'CST%')->count(),
    'routes' => (int) DB::table('routes')->where('code', 'like', 'CST%')->count(),
    'probe company' => (int) DB::table('companies')->where('name', 'Verify CST Second Company')->count(),
    'probe seats' => (int) DB::table('users')->where('email', 'like', 'verify-cst-%@fleetguard.local')->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
