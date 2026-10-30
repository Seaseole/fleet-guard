<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fuel\Actions\CreateBulkTank;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Actions\CreateFuelStation;
use App\Domain\Fuel\Actions\CreateFuelTank;
use App\Domain\Fuel\Actions\ReconcileBulkTank;
use App\Domain\Fuel\Actions\RecordFuelIssue;
use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Data\FuelIssueDto;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\FuelIssueSource;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelPurchase;
use App\Domain\Fuel\Models\FuelStation;
use App\Domain\Fuel\Models\FuelTank;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
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
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 3 slice 3a verification — the fuel spine: stations, tanks, purchases and trip issues
 * (DESIGN.md §4.3, §5.2, §6.8, §6.9, §10.9).
 *
 * The sentences this slice turns on:
 *
 * - a slip ties to itself: litres × price is the amount under §5.5's HALF_UP, and the table's CHECK
 *   states the same rule the DTO checks — including the slip whose product lands between cents,
 *   which is carried rather than refused — so a hand-written row cannot slip past either;
 * - an issue is fuel **onto a trip**: the tank must be that trip's truck's, the slip must still have
 *   litres on it, and the resulting cost is the issue's own frozen figure;
 * - the issue posts through Phase 2's doors — a trip cost of type `fuel` (account 5100, truck and
 *   trip dimensions) and an odometer reading whose source says where it came from — and the §6.6
 *   rollup's fuel bucket equals the cost that was posted;
 * - §10.9's machine is real: `recorded → posted` on the trail, and the states not built yet (bulk
 *   tank, kiosk) are refused **by name** rather than silently accepted;
 * - the slip is NOT posted to the ledger in this slice (`posted_to_ledger` false) because §6.8's
 *   payable needs Phase 5's bills — the seam is asserted, not assumed.
 *
 * It writes to the development database on the live MySQL 9.1 instance inside the `XFL` fixture
 * namespace, lifts the §6.3 barrier only to delete its own journal entries, and asserts that
 * everything it created is gone and the barrier is down again.
 *
 * Run: php scripts/verify-fuel.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['mail.default' => 'array', 'queue.default' => 'database']);

CurrentCompany::force(1);

/** @return list<string> */
function fuelCostJournalEntries(array $costIds): array
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

/**
 * The entries a non-trip-cost source posted — a stock receipt names its purchase, a dip names its
 * movement — so an interrupted run is cleared by the rows it created rather than by date.
 *
 * @param  list<string>  $sourceIds
 * @return list<string>
 */
function fuelSourceJournalEntries(string $sourceType, array $sourceIds): array
{
    if ($sourceIds === []) {
        return [];
    }

    return DB::table('gl_journal_entries')
        ->where('source_type', $sourceType)
        ->whereIn('source_id', array_map('strval', $sourceIds))
        ->pluck('id')
        ->all();
}

/** @param list<string> $entryIds */
function purgeFuelJournalEntries(array $entryIds, string $why): void
{
    if ($entryIds === []) {
        return;
    }

    LedgerBarrier::withLiftedGuard($why, null, function () use ($entryIds): void {
        DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
    });
}

/** One account's signed balance, debit minus credit, in base minor units. */
function ledgerBalance(int $accountId): int
{
    $debits = (int) DB::table('gl_journal_lines')->where('account_id', $accountId)->where('direction', 'debit')->sum('amount_base');
    $credits = (int) DB::table('gl_journal_lines')->where('account_id', $accountId)->where('direction', 'credit')->sum('amount_base');

    return $debits - $credits;
}

/** Delete rows by an id list, refusing to run on an empty list. */
function wipeFuelIds(string $table, string $column, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    return DB::table($table)->whereIn($column, $ids)->delete();
}

/*
 * Pre-clean: a run that died halfway leaves issues, slips, tanks, trips and journal entries the
 * next run would count as fixtures. Everything this file writes lives in the `XFL` namespace.
 * Children before parents: issues name trip costs and purchases; tanks name trucks.
 */
$xflJobIds = DB::table('freight_jobs')->where('notes', 'like', 'XFL%')->pluck('id')->all();
$xflTripIds = $xflJobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $xflJobIds)->pluck('id')->all();
$xflCostIds = $xflTripIds === [] ? [] : DB::table('trip_costs')->whereIn('trip_id', $xflTripIds)->pluck('id')->all();
$xflIssueIds = $xflTripIds === [] ? [] : DB::table('fuel_issues')->whereIn('trip_id', $xflTripIds)->pluck('id')->all();
$xflTruckIds = DB::table('trucks')->where('registration_no', 'like', '%XFL')->pluck('id')->all();
$xflEmployeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-XFL%')->pluck('id')->all();
$xflDriverIds = $xflEmployeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $xflEmployeeIds)->pluck('id')->all();
$xflPurchaseIds = DB::table('fuel_purchases')->where('invoice_no', 'like', 'XFL-%')->pluck('id')->all();
$xflBulkTankIds = DB::table('bulk_tanks')->where('name', 'like', 'XFL %')->pluck('id')->all();
$xflMovementIds = $xflBulkTankIds === [] ? [] : DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $xflBulkTankIds)->pluck('id')->all();

purgeFuelJournalEntries(fuelCostJournalEntries($xflCostIds), 'verify-fuel: clear an interrupted run\'s entries');
purgeFuelJournalEntries(fuelSourceJournalEntries('fuel_purchase', $xflPurchaseIds), 'verify-fuel: clear an interrupted run\'s stock receipts');
purgeFuelJournalEntries(fuelSourceJournalEntries('bulk_tank_dip', $xflMovementIds), 'verify-fuel: clear an interrupted run\'s dips');

wipeFuelIds('fuel_issues', 'id', $xflIssueIds);
wipeFuelIds('fuel_purchases', 'recorded_by', []);
DB::table('fuel_purchases')->where('invoice_no', 'like', 'XFL-%')->delete();
wipeFuelIds('bulk_tank_movements', 'bulk_tank_id', $xflBulkTankIds);
wipeFuelIds('bulk_tanks', 'id', $xflBulkTankIds);
DB::table('fuel_tanks')->whereIn('truck_id', $xflTruckIds ?: [0])->delete();
wipeFuelIds('trip_costs', 'trip_id', $xflTripIds);
wipeFuelIds('loads', 'trip_id', $xflTripIds);
wipeFuelIds('odometer_readings', 'trip_id', $xflTripIds);
wipeFuelIds('odometer_readings', 'truck_id', $xflTruckIds);
wipeFuelIds('vehicle_assignments', 'trip_id', $xflTripIds);
wipeFuelIds('operational_events', 'aggregate_id', array_values(array_unique(array_merge($xflTripIds, $xflIssueIds))));
wipeFuelIds('operational_events', 'aggregate_id', $xflJobIds);
wipeFuelIds('trips', 'id', $xflTripIds);
wipeFuelIds('freight_jobs', 'id', $xflJobIds);
wipeFuelIds('drivers', 'id', $xflDriverIds);
wipeFuelIds('employees', 'id', $xflEmployeeIds);
wipeFuelIds('trucks', 'id', $xflTruckIds);
DB::table('fuel_stations')->where('name', 'like', 'XFL %')->delete();
DB::table('routes')->where('code', 'like', 'XFL%')->delete();
DB::table('customers')->where('code', 'like', 'XFL%')->delete();
// A fixture company this file creates has no chart of its own; a hand-seeded one would block the
// delete below, so the accounts go first.
$xflCompanyIds = DB::table('companies')->where('name', 'Verify XFL Second Company')->pluck('id')->all();
wipeFuelIds('gl_accounts', 'company_id', $xflCompanyIds);
DB::table('companies')->where('name', 'Verify XFL Second Company')->delete();

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

    /*
     * The journal counter needs the same treatment, for the same reason: this file posts entries and
     * purges them, so a counter left ahead of the highest surviving entry collides the moment the
     * ledger posts again — `1062 Duplicate entry '1-JV-FY26/27-000005'`, which is not a bug in the
     * allocator but debris from a run that died between allocating and cleaning. Scoped by the
     * sequence's own period key, so a prior fiscal year's numbers never raise this one's.
     */
    $journal = DB::table('number_sequences')
        ->where('company_id', 1)
        ->where('scope', 'journal_entry')
        ->first();

    if ($journal !== null) {
        $prefix = 'JV-'.$journal->period_key.'-';

        $highestEntry = DB::table('gl_journal_entries')
            ->where('company_id', 1)
            ->where('entry_no', 'like', $prefix.'%')
            ->pluck('entry_no')
            ->map(static fn (string $number): int => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        DB::table('number_sequences')
            ->where('id', $journal->id)
            ->update(['next_value' => $highestEntry + 1, 'updated_at' => now()]);
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

$runStartedAt = Carbon::now();
$owner = User::query()->findOrFail(1);
Auth::login($owner);
CurrentCompany::force(1);

$created = [
    'truck_ids' => [], 'employee_ids' => [], 'driver_ids' => [],
    'job_ids' => [], 'trip_ids' => [], 'tank_ids' => [], 'station_ids' => [],
    'bulk_tank_ids' => [], 'purchase_ids' => [], 'issue_ids' => [], 'cost_ids' => [],
];

$baseCurrency = CurrentCompany::model()->base_currency_code;

/* ------------------------------------------------------------------------- */
echo "== §4.3 the schema keeps the promises the docs make (live MySQL 9.1) ==\n";

$tankDdl = (string) ((array) DB::select('show create table fuel_tanks')[0])['Create Table'];
check(str_contains($tankDdl, 'fuel_tanks_company_truck_kind_unique'), 'a truck has one tank of each kind, not two rows that split its fuel');
check(str_contains($tankDdl, 'fuel_tanks_kind_allowed') && str_contains($tankDdl, 'fuel_tanks_capacity_positive'), '...and the kind vocabulary and capacity are CHECKs, not conventions');

$stationDdl = (string) ((array) DB::select('show create table fuel_stations')[0])['Create Table'];
check(str_contains($stationDdl, 'fuel_stations_company_name_unique'), 'a station is unique per company by name');
check(str_contains($stationDdl, 'fuel_stations_country_upper'), '...and its country code is stored upper-case');

$purchaseDdl = (string) ((array) DB::select('show create table fuel_purchases')[0])['Create Table'];

foreach ([
    'fuel_purchases_company_source_unique',
    'fuel_purchases_price_times_litres_is_amount',
    'fuel_purchases_amount_positive',
    'fuel_purchases_litres_positive',
    'fuel_purchases_rate_positive',
    'fuel_purchases_currency_upper',
    'fuel_purchases_source_allowed',
] as $name) {
    check(str_contains($purchaseDdl, $name), "fuel_purchases carries {$name}");
}

check(Schema::hasColumns('fuel_purchases', ['currency_code', 'amount', 'fx_rate', 'fx_rate_source', 'fx_rate_date', 'fx_rate_ref', 'base_currency_code', 'base_amount']), 'fuel_purchases carries §5.2\'s eight columns');
check(Schema::hasColumn('fuel_purchases', 'posted_to_ledger'), '...and §6.8\'s posting seam is a column, not a promise');

$issueDdl = (string) ((array) DB::select('show create table fuel_issues')[0])['Create Table'];

foreach ([
    'fuel_issues_trip_company_fk',
    'fuel_issues_purchase_fk',
    'fuel_issues_trip_cost_fk',
    'fuel_issues_litres_positive',
    'fuel_issues_cost_not_negative',
    'fuel_issues_source_allowed',
    'fuel_issues_status_allowed',
    'fuel_issues_previous_state_allowed',
    'fuel_issues_purchase_matches_source',
] as $name) {
    check(str_contains($issueDdl, $name), "fuel_issues carries {$name}");
}

check(Schema::hasColumns('fuel_issues', ['previous_state', 'state_changed_at', 'reversal_of_id']), '...and §10.1/§10.9\'s state columns, including the reversal link');

echo "\n== §4.3/§15.2 phase 3 the bulk tank's promises are CHECKs too ==\n";

$bulkTankDdl = (string) ((array) DB::select('show create table bulk_tanks')[0])['Create Table'];
check(str_contains($bulkTankDdl, 'bulk_tanks_company_name_unique'), 'a bulk tank is unique per company by name');
check(str_contains($bulkTankDdl, 'bulk_tanks_capacity_positive'), '...and its capacity is a CHECK, not a convention');
check(str_contains($bulkTankDdl, 'bulk_tanks_empty_is_zero'), '...and an empty tank is worth nothing and has no average — the residue cannot hide in a column');

$movementDdl = (string) ((array) DB::select('show create table bulk_tank_movements')[0])['Create Table'];
check(str_contains($movementDdl, 'bulk_tank_movements_kind_allowed'), 'the stock book\'s vocabulary is a receipt, an issue or a dip');
check(str_contains($movementDdl, 'bulk_tank_movements_moved_something') && str_contains($movementDdl, 'bulk_tank_movements_signs_agree'), '...a movement moves something, and litres and value move the same way');
check(str_contains($movementDdl, 'bulk_tank_movements_source_unique'), '...and a purchase or issue can only move the tank once');

check(Schema::hasColumn('fuel_purchases', 'bulk_tank_id') && str_contains($purchaseDdl, 'fuel_purchases_bulk_delivery_posted'), 'a delivery into stock must be posted the moment it is recorded');
check(Schema::hasColumn('fuel_issues', 'bulk_tank_id') && str_contains($issueDdl, 'fuel_issues_bulk_tank_matches_source'), '...and a bulk issue names its tank, a slip issue names no tank');

$costDdl = (string) ((array) DB::select('show create table trip_costs')[0])['Create Table'];
check(str_contains($costDdl, "'stock'"), 'a trip cost can be settled from stock, and the table knows the word');

/* ------------------------------------------------------------------------- */
echo "\n== §4.3 stations: master data, refused before it can split a pump's history ==\n";

$createStation = app(CreateFuelStation::class);

refuses(
    'a station with a blank name is refused',
    fn () => $createStation('   '),
    InvalidArgumentException::class,
    'needs a name'
);

refuses(
    'a station in a three-letter country is refused',
    fn () => $createStation('XFL Bad Country', countryCode: 'BWA'),
    InvalidArgumentException::class,
    'two-letter country'
);

$station = $createStation('XFL Engen Gaborone', 'Gaborone');
$created['station_ids'][] = $station->id;

check($station->label() === 'XFL Engen Gaborone · Gaborone', 'a station prints its town when it has one');
check($station->country_code === 'BW', '...and defaults to the fleet\'s home country');

refuses(
    'the same station is refused by name',
    fn () => $createStation('XFL Engen Gaborone'),
    InvalidArgumentException::class,
    'already exists'
);

/* ------------------------------------------------------------------------- */
echo "\n== fixtures: a truck with two tanks, and a trip that can be fuelled ==\n";

$customer = (app(CreateCustomer::class))(new CustomerDto(name: 'XFL Verify Customer', code: 'XFL-CUST', paymentTerms: 'net_30'));
$route = (app(CreateRoute::class))(new RouteDto(
    code: 'XFL-GBE-FRW',
    origin: 'Gaborone',
    destination: 'Francistown',
    originCountryCode: 'BW',
    destinationCountryCode: 'BW',
    distanceKm: '430',
));

$truck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 82 XFL',
    fleetNo: 'T-XFL1',
    vin: 'VINXFL000000000000001',
    make: 'Probe',
    model: 'Fuel',
    odometerKm: 120_000,
    mainTankLitres: 400,
    auxTankLitres: 200,
));
$created['truck_ids'][] = $truck->id;

$otherTruck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 83 XFL',
    fleetNo: 'T-XFL2',
    vin: 'VINXFL000000000000002',
    make: 'Probe',
    model: 'Fuel',
    odometerKm: 90_000,
    mainTankLitres: 300,
));
$created['truck_ids'][] = $otherTruck->id;

$employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-XFL001', name: 'XFL Verify Driver', hiredOn: '2024-02-01'));
$created['employee_ids'][] = $employee->id;

$driver = (app(CreateDriver::class))(new DriverDto(
    employeeId: $employee->id,
    licenceNo: 'DL-XFL001',
    licenceCode: 'EC',
    licenceExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    permitExpiresOn: Carbon::now()->addMonths(9)->toDateString(),
    allowanceRateBase: 35_000,
    kioskEnabled: true,
));
$created['driver_ids'][] = $driver->id;

$job = (app(CreateJob::class))(new JobDto(
    customerId: $customer->id,
    routeId: $route->id,
    commodity: 'cement',
    tonnes: 30.0,
    requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
    requiredDeliveryOn: Carbon::now()->addDays(4)->toDateString(),
    notes: 'XFL fuel job',
));
$created['job_ids'][] = $job->id;

$job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '900.00', currencyCode: $baseCurrency), 1);
$job = (app(AcceptJob::class))($job, 1);

$trip = (app(CreateTrip::class))($job, 1);
$trip->forceFill(['truck_id' => $truck->id, 'driver_id' => $driver->id])->save();
$created['trip_ids'][] = $trip->id;
$trip = $trip->fresh();

/* ------------------------------------------------------------------------- */
echo "\n== §4.3 fitting tanks: taken from the truck's stated capacity, one per kind ==\n";

$createTank = app(CreateFuelTank::class);

$mainTank = $createTank($truck->id, FuelTankKind::Main);
$created['tank_ids'][] = $mainTank->id;

check($mainTank->capacity_litres === 400, 'the main tank takes the truck\'s stated 400 L');

refuses(
    'a second main tank on the same truck is refused',
    fn () => $createTank($truck->id, FuelTankKind::Main),
    InvalidArgumentException::class,
    'already has a main tank'
);

$auxTank = $createTank($truck->id, FuelTankKind::Auxiliary);
$created['tank_ids'][] = $auxTank->id;

check($auxTank->capacity_litres === 200, '...and the auxiliary takes its stated 200 L');

$otherMainTank = $createTank($otherTruck->id, FuelTankKind::Main);
$created['tank_ids'][] = $otherMainTank->id;

$bareTruck = (app(CreateTruck::class))(new TruckDto(
    registrationNo: 'B 84 XFL',
    fleetNo: 'T-XFL3',
    vin: 'VINXFL000000000000003',
    make: 'Probe',
    model: 'Fuel',
    odometerKm: 50_000,
));
$created['truck_ids'][] = $bareTruck->id;

refuses(
    'a truck that states no auxiliary capacity needs it given',
    fn () => $createTank($bareTruck->id, FuelTankKind::Auxiliary),
    InvalidArgumentException::class,
    'states no auxiliary'
);

/* ------------------------------------------------------------------------- */
echo "\n== §4.3/§5.2 purchases: the slip ties to itself and the rate is frozen ==\n";

$createPurchase = app(CreateFuelPurchase::class);

refuses(
    'a slip whose litres × price is not its amount is refused, naming both figures',
    fn () => $createPurchase(new FuelPurchaseDto(
        amount: Money::ofMinor(100_000, $baseCurrency),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: Carbon::now(),
        purchasedOn: Carbon::now(),
        litresX100: 40_000,
        pricePerLitreMinor: 1850,
        stationId: $station->id,
        invoiceNo: 'XFL-BAD',
    )),
    InvalidArgumentException::class,
    'per litre is'
);

refuses(
    'a slip with no litres is refused',
    fn () => $createPurchase(new FuelPurchaseDto(
        amount: Money::ofMinor(1, $baseCurrency),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: Carbon::now(),
        purchasedOn: Carbon::now(),
        litresX100: 0,
        pricePerLitreMinor: 1850,
        stationId: $station->id,
    )),
    InvalidArgumentException::class,
    'positive litre count'
);

// 400.00 L at BWP 18.50 = BWP 7 400.00 → 740 000 minor units.
$purchase = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(740_000, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 40_000,
    pricePerLitreMinor: 1850,
    stationId: $station->id,
    invoiceNo: 'XFL-SLIP-1',
    sourceUuid: 'XFL-SLIP-1',
));
$created['purchase_ids'][] = $purchase->id;

check($purchase->litresMajor() === '400.00', 'the slip reads back as 400.00 litres');
check($purchase->baseMoney()->minorUnits === 740_000, '...at BWP 7 400.00 in base money');
check($purchase->posted_to_ledger === false, '...and is NOT posted to the ledger in this slice (§6.8 needs Phase 5\'s bills)');

$retry = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(740_000, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 40_000,
    pricePerLitreMinor: 1850,
    stationId: $station->id,
    invoiceNo: 'XFL-SLIP-1',
    sourceUuid: 'XFL-SLIP-1',
));

check($retry->id === $purchase->id, 'a re-delivered slip (same source_uuid) is the same purchase, not a second one');
check((int) DB::table('fuel_purchases')->where('invoice_no', 'XFL-SLIP-1')->count() === 1, '...and the table holds exactly one row for it');

/*
 * The slip that lands between cents. 25.30 L at BWP 19.75 is BWP 499.675, and §5.5 rounds the cost
 * HALF_UP to 499.68 — so `amount × 100 = litres × price` is a rule, not an identity, and the CHECK
 * has to state the rule. Written as the bare identity it refused 99 slips in 100, this one among
 * them: `SQLSTATE[HY000] 3819 Check constraint 'fuel_purchases_price_times_litres_is_amount'`.
 */
$subCent = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(49_968, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 2_530,
    pricePerLitreMinor: 1975,
    stationId: $station->id,
    invoiceNo: 'XFL-SUB-CENT',
));
$created['purchase_ids'][] = $subCent->id;

check($subCent->amount === 49_968, '25.30 L at BWP 19.75 (BWP 499.675) is carried as 499.68, HALF_UP');
check($subCent->litresMajor() === '25.30', '...and its litre figure is untouched');

refuses(
    'a hand-written row a minor unit under the slip\'s own figure is refused by the CHECK',
    fn () => DB::table('fuel_purchases')->insert([
        'id' => (string) Str::ulid(),
        'company_id' => CurrentCompany::id(),
        'currency_code' => $baseCurrency,
        'amount' => 49_967,
        'fx_rate' => 1,
        'fx_rate_source' => FxRateSource::Manual->value,
        'fx_rate_date' => Carbon::now()->toDateString(),
        'base_currency_code' => $baseCurrency,
        'base_amount' => 49_967,
        'purchased_on' => Carbon::now()->toDateString(),
        'litres' => 2_530,
        'price_per_litre_minor' => 1975,
        'posted_to_ledger' => false,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]),
    QueryException::class,
    'fuel_purchases_price_times_litres_is_amount'
);

/* ------------------------------------------------------------------------- */
echo "\n== §4.3 issuing fuel: onto a trip, from that trip's own truck ==\n";

$issue = app(RecordFuelIssue::class);

refuses(
    'a bulk-tank issue that names no tank is refused before it reaches the database',
    fn () => new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 10_000,
        odometerKm: 120_400,
        occurredOn: Carbon::now(),
        purchaseId: null,
        source: FuelIssueSource::BulkTank,
    ),
    InvalidArgumentException::class,
    'has to name the tank'
);

refuses(
    'a slip issue that names a bulk tank is refused — one diesel, one source',
    fn () => new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 10_000,
        odometerKm: 120_400,
        occurredOn: Carbon::now(),
        purchaseId: $purchase->id,
        bulkTankId: 1,
    ),
    InvalidArgumentException::class,
    'Only a bulk-tank issue'
);

refuses(
    'a kiosk issue is refused by the slice that will build it',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 10_000,
        odometerKm: 120_400,
        occurredOn: Carbon::now(),
        purchaseId: $purchase->id,
        source: FuelIssueSource::Kiosk,
    )),
    InvalidArgumentException::class,
    'Phase 3d'
);

refuses(
    'another truck\'s tank is refused',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $otherMainTank->id,
        litresX100: 10_000,
        odometerKm: 120_400,
        occurredOn: Carbon::now(),
        purchaseId: $purchase->id,
    )),
    InvalidArgumentException::class,
    'not an active tank'
);

refuses(
    'more litres than the slip has left is refused, naming the remaining figure',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 40_100,
        odometerKm: 120_400,
        occurredOn: Carbon::now(),
        purchaseId: $purchase->id,
    )),
    InvalidArgumentException::class,
    'litres left'
);

// 250.50 L at BWP 18.50 = BWP 4 634.25 → 463 425 minor units.
$first = $issue($trip, new FuelIssueDto(
    tankId: $mainTank->id,
    litresX100: 25_050,
    odometerKm: 120_400,
    occurredOn: Carbon::now(),
    purchaseId: $purchase->id,
), 1);
$created['issue_ids'][] = $first->id;

check($first->status === FuelIssueStatus::Posted, 'the issue is posted');
check($first->cost_base === 463_425 && $first->base_currency_code === $baseCurrency, '...its cost is the slip\'s price at the slip\'s rate, frozen on the row');
check($first->trip_cost_id !== null, '...and it names the trip cost it posted');

$cost = TripCost::query()->findOrFail($first->trip_cost_id);
$created['cost_ids'][] = $cost->id;

check($cost->cost_type === TripCostType::Fuel, 'the trip cost is type fuel');
check($cost->paid_with === TripCostPayment::SupplierCredit, '...paid on the supplier account, because a pump card is not cash');
check($cost->base_amount === 463_425, '...and its base amount is the issue\'s frozen cost, to the minor unit');

$reading = DB::table('odometer_readings')
    ->where('trip_id', $trip->id)
    ->where('odometer_km', 120_400)
    ->first();

check($reading !== null && $reading->source === 'fuel_issue', 'the issue records an odometer reading whose source says it came from the pump');
check((int) DB::table('trucks')->where('id', $truck->id)->value('odometer_km') === 120_400, '...and the truck\'s projection moves to it');

$rollup = (int) DB::table('trips')->where('id', $trip->id)->value('fuel_cost_base');
check($rollup === 463_425, 'the §6.6 rollup\'s fuel bucket equals the cost that was posted ('.$rollup.')');

$trail = DB::table('operational_events')
    ->where('aggregate_type', 'fuel_issue')
    ->where('aggregate_id', $first->id)
    ->orderBy('id')
    ->get(['from_state', 'to_state'])
    ->all();

check(count($trail) === 1 && $trail[0]->from_state === 'recorded' && $trail[0]->to_state === 'posted', '§10.9\'s move is on the trail exactly once: recorded → posted');

$entry = DB::table('gl_journal_entries')
    ->where('source_type', 'trip_cost')
    ->where('source_id', $cost->id)
    ->first();

check($entry !== null, 'the cost has a journal entry that names it as its source');

if ($entry !== null) {
    $lines = DB::table('gl_journal_lines')->where('entry_id', $entry->id)->get()->all();
    check(count($lines) === 2, '...with exactly two lines');

    $fuelLine = collect($lines)->firstWhere('direction', 'debit');
    check($fuelLine !== null && $fuelLine->truck_id === $truck->id && $fuelLine->trip_id === $trip->id, '...and the fuel debit carries both dimensions account 5100 requires (§6.1)');
}

$remaining = $purchase->fresh()->litresRemainingX100();
check($remaining === 14_950, 'the slip has 149.50 litres left after the issue ('.$remaining.' × 100)');

/* ------------------------------------------------------------------------- */
echo "\n== §7 R7 a tank that takes more than it holds is flagged, not refused ==\n";

$secondSlip = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(1_110_000, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 60_000,
    pricePerLitreMinor: 1850,
    stationId: $station->id,
    invoiceNo: 'XFL-SLIP-2',
));
$created['purchase_ids'][] = $secondSlip->id;

$overCapacity = $issue($trip, new FuelIssueDto(
    tankId: $mainTank->id,
    litresX100: 45_000,
    odometerKm: 120_900,
    occurredOn: Carbon::now(),
    purchaseId: $secondSlip->id,
    note: 'Filled main and auxiliary',
), 1);
$created['issue_ids'][] = $overCapacity->id;
$created['cost_ids'][] = $overCapacity->trip_cost_id;

check($overCapacity->exception_reason !== null && str_contains((string) $overCapacity->exception_reason, 'capacity'), 'a 450 L issue into a 400 L tank is accepted with its reason recorded, not silently swallowed');
check($overCapacity->status === FuelIssueStatus::Posted, '...and still posts, because refusing would push the discrepancy into a wrong number');

/* ------------------------------------------------------------------------- */
echo "\n== §4.3/§15.2 phase 3 a bulk tank: opened empty, fed by delivery, valued by average ==\n";

$createBulkTank = app(CreateBulkTank::class);

refuses(
    'a bulk tank with a blank name is refused',
    fn () => $createBulkTank('   ', 1_000),
    InvalidArgumentException::class,
    'up to 80 characters'
);

refuses(
    'a bulk tank with no capacity is refused',
    fn () => $createBulkTank('XFL Empty Tank', 0),
    InvalidArgumentException::class,
    'between 1 and 65,535'
);

$bulk = $createBulkTank('XFL Yard Tank', 20_000);
$created['bulk_tank_ids'][] = $bulk->id;

check(
    $bulk->stock_litres_x100 === 0 && $bulk->stock_value_base === 0 && $bulk->average_cost_per_litre_minor === 0,
    'a bulk tank opens empty and worth nothing — stock only moves through a movement'
);

refuses(
    'the same tank name is refused in the same company',
    fn () => $createBulkTank('XFL Yard Tank', 5_000),
    InvalidArgumentException::class,
    'already has a bulk tank'
);

$chart = app(ChartOfAccounts::class)->byCode();
$stockAccountId = $chart['1180']?->id ?? throw new RuntimeException('1180 Bulk fuel stock is missing from the chart');
$varianceAccountId = $chart['5110']?->id ?? throw new RuntimeException('5110 Fuel stock variance is missing from the chart');
$payableAccountId = $chart['2110']?->id ?? throw new RuntimeException('2110 Accounts payable is missing from the chart');

// 10,000.00 L at BWP 20.00 = BWP 200,000.00 → 20,000,000 minor units.
$delivery = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(20_000_000, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 1_000_000,
    pricePerLitreMinor: 2000,
    stationId: $station->id,
    invoiceNo: 'XFL-BULK-1',
    bulkTankId: $bulk->id,
));
$created['purchase_ids'][] = $delivery->id;

check($delivery->posted_to_ledger === true, 'a delivery into a tank is posted in the same transaction as its row (§6.8)');
check($delivery->isBulkDelivery(), '...and the row knows it went into stock');
check($delivery->litresRemainingX100() === 0, '...so its litres are not also offered as a slip the trucks could drain');

$bulk->refresh();
check($bulk->stock_litres_x100 === 1_000_000 && $bulk->stock_value_base === 20_000_000, '10,000 L at BWP 20.00 is BWP 200,000 of stock');
check($bulk->average_cost_per_litre_minor === 2000, '...and the average is the price that was paid');

$receiptEntry = DB::table('gl_journal_entries')
    ->where('source_type', 'fuel_purchase')
    ->where('source_id', $delivery->id)
    ->first();

check($receiptEntry !== null, 'the delivery has a journal entry that names it');

if ($receiptEntry !== null) {
    $receiptLines = DB::table('gl_journal_lines')->where('entry_id', $receiptEntry->id)->get()->all();
    $debit = collect($receiptLines)->firstWhere('direction', 'debit');
    $credit = collect($receiptLines)->firstWhere('direction', 'credit');

    check(
        count($receiptLines) === 2
            && $debit !== null && (int) $debit->account_id === (int) $stockAccountId && (int) $debit->amount_base === 20_000_000
            && $credit !== null && (int) $credit->account_id === (int) $payableAccountId && (int) $credit->amount_base === 20_000_000,
        '...Dr 1180 Bulk fuel stock / Cr 2110 Supplier payable, to the minor unit'
    );
}

// 5,000.00 L at BWP 24.00 = BWP 120,000.00 → 12,000,000 minor units.
$secondDelivery = $createPurchase(new FuelPurchaseDto(
    amount: Money::ofMinor(12_000_000, $baseCurrency),
    rate: '1',
    rateSource: FxRateSource::Manual,
    rateDate: Carbon::now(),
    purchasedOn: Carbon::now(),
    litresX100: 500_000,
    pricePerLitreMinor: 2400,
    stationId: $station->id,
    invoiceNo: 'XFL-BULK-2',
    bulkTankId: $bulk->id,
));
$created['purchase_ids'][] = $secondDelivery->id;

$bulk->refresh();
check($bulk->stock_litres_x100 === 1_500_000 && $bulk->stock_value_base === 32_000_000, 'a second delivery adds litres and money, not an average of averages');
check($bulk->average_cost_per_litre_minor === 2133, '...and the moving average is value over litres: 2,133 minor a litre ('.$bulk->average_cost_per_litre_minor.')');

/* ------------------------------------------------------------------------- */
echo "\n== §6.8/§15.2 a truck takes diesel out of the tank, at the tank's own average ==\n";

$bulkIssue = $issue($trip, new FuelIssueDto(
    tankId: $mainTank->id,
    litresX100: 10_000,
    odometerKm: 121_200,
    occurredOn: Carbon::now(),
    source: FuelIssueSource::BulkTank,
    bulkTankId: $bulk->id,
), 1);
$created['issue_ids'][] = $bulkIssue->id;
$created['cost_ids'][] = $bulkIssue->trip_cost_id;

check($bulkIssue->cost_base === 213_300, '100.00 L at BWP 21.33 is BWP 2,133.00, frozen on the issue ('.$bulkIssue->cost_base.')');
check($bulkIssue->bulk_tank_id === $bulk->id && $bulkIssue->purchase_id === null, '...and the issue names the tank it emptied, never a slip');
check($bulkIssue->status === FuelIssueStatus::Posted, '...and it posts like any other issue');

$bulkCost = TripCost::query()->findOrFail($bulkIssue->trip_cost_id);
check($bulkCost->paid_with === TripCostPayment::Stock, 'the cost is settled from stock, not paid for a second time (§6.8)');

$bulkEntry = DB::table('gl_journal_entries')
    ->where('source_type', 'trip_cost')
    ->where('source_id', $bulkCost->id)
    ->first();

$bulkCredit = $bulkEntry === null
    ? null
    : DB::table('gl_journal_lines')->where('entry_id', $bulkEntry->id)->where('direction', 'credit')->first();

check($bulkCredit !== null && (int) $bulkCredit->account_id === (int) $stockAccountId, '...and it credits 1180 Bulk fuel stock, the account the diesel was paid into');
check($bulkCredit !== null && (int) $bulkCredit->amount_base === 213_300, '...by exactly the issue\'s cost, so the stock value and the account cannot drift');

$bulk->refresh();
check($bulk->stock_litres_x100 === 1_490_000 && $bulk->stock_value_base === 31_786_700, 'the tank holds 100 L less and exactly BWP 2,133.00 less value');

refuses(
    'an issue larger than the tank holds is refused, naming the stock',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 1_500_000,
        odometerKm: 121_250,
        occurredOn: Carbon::now(),
        source: FuelIssueSource::BulkTank,
        bulkTankId: $bulk->id,
    ), 1),
    InvalidArgumentException::class,
    'cannot give what it does not have'
);

$bulk->forceFill(['active' => false])->save();

refuses(
    'an inactive bulk tank refuses an issue',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 1_000,
        odometerKm: 121_250,
        occurredOn: Carbon::now(),
        source: FuelIssueSource::BulkTank,
        bulkTankId: $bulk->id,
    ), 1),
    InvalidArgumentException::class,
    'not an active tank'
);

refuses(
    '...and refuses a delivery',
    fn () => $createPurchase(new FuelPurchaseDto(
        amount: Money::ofMinor(2_000_000, $baseCurrency),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: Carbon::now(),
        purchasedOn: Carbon::now(),
        litresX100: 100_000,
        pricePerLitreMinor: 2000,
        invoiceNo: 'XFL-BULK-OFF',
        bulkTankId: $bulk->id,
    )),
    InvalidArgumentException::class,
    'no longer in use'
);

$bulk->forceFill(['active' => true])->save();

/* ------------------------------------------------------------------------- */
echo "\n== §15.2 a dip is a fact to record, and its variance goes to 5110 ==\n";

$reconcile = app(ReconcileBulkTank::class);

$short = $reconcile($bulk->fresh(), 1_488_000, Carbon::now(), 'XFL dip', 1);
check($short->kind === BulkTankMovementKind::Dip && $short->litres_delta_x100 === -2_000, 'a dip is recorded as the difference between the book and the dipstick (-20.00 L)');
check($short->value_delta_base === -42_660, '...valued at the tank average, 2,133 minor a litre ('.$short->value_delta_base.')');
check($short->stock_litres_x100_after === 1_488_000 && $short->stock_value_base_after === 31_744_040, '...with the figures the tank held afterwards, stored rather than re-derived');

$dipEntry = DB::table('gl_journal_entries')
    ->where('source_type', 'bulk_tank_dip')
    ->where('source_id', $short->id)
    ->first();

check($dipEntry !== null, 'a shortage posts an entry that names the movement');

if ($dipEntry !== null) {
    $dipLines = DB::table('gl_journal_lines')->where('entry_id', $dipEntry->id)->get()->all();
    $dipDebit = collect($dipLines)->firstWhere('direction', 'debit');
    $dipCredit = collect($dipLines)->firstWhere('direction', 'credit');

    check(
        count($dipLines) === 2
            && $dipDebit !== null && (int) $dipDebit->account_id === (int) $varianceAccountId && (int) $dipDebit->amount_base === 42_660
            && $dipCredit !== null && (int) $dipCredit->account_id === (int) $stockAccountId,
        '...Dr 5110 Fuel stock variance / Cr 1180 Bulk fuel stock'
    );
}

$gain = $reconcile($bulk->fresh(), 1_490_000, Carbon::now(), 'XFL dip found more', 1);
check($gain->litres_delta_x100 === 2_000 && $gain->value_delta_base === 42_660, 'a dip that finds more puts the litres and their value back');

$gainEntry = DB::table('gl_journal_entries')
    ->where('source_type', 'bulk_tank_dip')
    ->where('source_id', $gain->id)
    ->first();

check($gainEntry !== null, '...and posts the reverse: the value comes back out of 5110');

if ($gainEntry !== null) {
    $gainDebit = DB::table('gl_journal_lines')->where('entry_id', $gainEntry->id)->where('direction', 'debit')->first();
    $gainCredit = DB::table('gl_journal_lines')->where('entry_id', $gainEntry->id)->where('direction', 'credit')->first();

    check(
        $gainDebit !== null && $gainCredit !== null
            && (int) $gainDebit->account_id === (int) $stockAccountId
            && (int) $gainCredit->account_id === (int) $varianceAccountId,
        '...Dr 1180 / Cr 5110'
    );
}

$agree = $reconcile($bulk->fresh(), 1_490_000, Carbon::now(), 'XFL dip agrees', 1);
check($agree->litres_delta_x100 === 0 && $agree->value_delta_base === 0, 'a dip that agrees with the book is still recorded, with two zeroes');
check(
    DB::table('gl_journal_entries')->where('source_type', 'bulk_tank_dip')->where('source_id', $agree->id)->count() === 0,
    '...and posts nothing, because there is no variance to book'
);

refuses(
    'a negative dip reading is refused',
    fn () => $reconcile($bulk->fresh(), -1, Carbon::now(), null, 1),
    InvalidArgumentException::class,
    'cannot be negative'
);

$stockBalance = ledgerBalance((int) $stockAccountId);

check($stockBalance === $bulk->fresh()->stock_value_base, '1180\'s ledger balance equals the tank\'s value — the account and the book are one figure ('.$stockBalance.')');

/* ------------------------------------------------------------------------- */
echo "\n== §15.2 draining the tank takes the exact value, so an empty tank is worth nothing ==\n";

$beforeDrain = $bulk->fresh();

$drain = $issue($trip, new FuelIssueDto(
    tankId: $mainTank->id,
    litresX100: $beforeDrain->stock_litres_x100,
    odometerKm: 121_300,
    occurredOn: Carbon::now(),
    source: FuelIssueSource::BulkTank,
    bulkTankId: $bulk->id,
), 1);
$created['issue_ids'][] = $drain->id;
$created['cost_ids'][] = $drain->trip_cost_id;

check($drain->cost_base === $beforeDrain->stock_value_base, 'emptying the tank costs the value the book says it holds, to the minor unit');

$emptied = $bulk->fresh();
check(
    $emptied->stock_litres_x100 === 0 && $emptied->stock_value_base === 0 && $emptied->average_cost_per_litre_minor === 0,
    '...and the empty tank is zero of everything, so the CHECK holds rather than leaving a rounding residue'
);

$stockBalanceAfterDrain = ledgerBalance((int) $stockAccountId);

check($stockBalanceAfterDrain === 0, '...and 1180 holds nothing when the tank does ('.$stockBalanceAfterDrain.')');

refuses(
    'an issue from an empty tank is refused',
    fn () => $issue($trip, new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 1_000,
        odometerKm: 121_400,
        occurredOn: Carbon::now(),
        source: FuelIssueSource::BulkTank,
        bulkTankId: $bulk->id,
    ), 1),
    InvalidArgumentException::class,
    'cannot give what it does not have'
);

$ledgerCheck = Artisan::call('ledger:verify');
check($ledgerCheck === 0, 'the ledger recomputes from its own lines with no drift', mb_substr(str_replace("\n", ' ', Artisan::output()), 0, 200));

/* ------------------------------------------------------------------------- */
echo "\n== §10.2 a frozen trip cannot acquire fuel ==\n";

$frozenTrip = (app(CreateTrip::class))($job, 1);
$frozenTrip->forceFill([
    'status' => TripStatus::Closed->value,
    'costs_frozen' => true,
    'truck_id' => $truck->id,
    'driver_id' => $driver->id,
])->save();
$created['trip_ids'][] = $frozenTrip->id;

refuses(
    'a closed trip refuses a fuel issue through RecordTripCost\'s own rule',
    fn () => $issue($frozenTrip->fresh(), new FuelIssueDto(
        tankId: $mainTank->id,
        litresX100: 1000,
        odometerKm: 121_000,
        occurredOn: Carbon::now(),
        purchaseId: $secondSlip->id,
    ), 1),
    InvalidArgumentException::class,
    'costs are final'
);

/* ------------------------------------------------------------------------- */
echo "\n== §9.3 the accountant reads fuel and does not record it ==\n";

/** @var User $accountant */
$accountant = User::query()->firstOrNew(['email' => 'verify-xfl-accountant@fleetguard.local']);
$accountant->name = 'Verify XFL Accountant';
$accountant->company_id = $owner->company_id;
$accountant->password = Str::random(32);
$accountant->email_verified_at = Carbon::now();
$accountant->save();
app(ChangeUserRole::class)($accountant->fresh(), RoleKey::Accountant);
$accountant = $accountant->fresh();

check(Gate::forUser($accountant)->allows('fuel.view'), 'the accountant may see fuel (§9.3)');
check(! Gate::forUser($accountant)->allows('fuel.record'), '...but may not record it');

Auth::login($accountant);
refuses(
    'an accountant cannot register a station',
    fn () => $createStation('XFL Accountant Pump'),
    InvalidArgumentException::class,
    'workshop recording'
);
Auth::login($owner);
CurrentCompany::force(1);

/* ------------------------------------------------------------------------- */
echo "\n== §8 tenancy: the fuel register is scoped, not filtered by habit ==\n";

$stamp = Carbon::now()->toDateTimeString();

DB::table('companies')->insert([
    'id' => 2,
    'name' => 'Verify XFL Second Company',
    'timezone' => 'Africa/Gaborone',
    'fiscal_year_starts_on' => '2000-01-01',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

CurrentCompany::force(2);

check(FuelStation::query()->count() === 0, 'a second company sees none of the first company\'s stations');
check(FuelPurchase::query()->count() === 0, '...nor its purchases');
check(FuelIssue::query()->count() === 0, '...nor its issues');
check(FuelTank::query()->count() === 0, '...nor its tanks');
check(BulkTank::query()->count() === 0, '...nor its bulk tanks');

CurrentCompany::force(1);

check(FuelStation::query()->count() >= 1, 'the first company still sees its own station');

$crossCompany = $createStation('XFL Cross Company Pump', countryCode: 'ZA');
$created['station_ids'][] = $crossCompany->id;

CurrentCompany::force(2);

refuses(
    'a purchase naming another company\'s station is refused',
    fn () => $createPurchase(new FuelPurchaseDto(
        amount: Money::ofMinor(740_000, $baseCurrency),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: Carbon::now(),
        purchasedOn: Carbon::now(),
        litresX100: 40_000,
        pricePerLitreMinor: 1850,
        stationId: $crossCompany->id,
    )),
    InvalidArgumentException::class,
    'not in this company'
);

refuses(
    'a delivery naming another company\'s bulk tank is refused',
    fn () => $createPurchase(new FuelPurchaseDto(
        amount: Money::ofMinor(20_000_000, $baseCurrency),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: Carbon::now(),
        purchasedOn: Carbon::now(),
        litresX100: 1_000_000,
        pricePerLitreMinor: 2000,
        invoiceNo: 'XFL-CROSS-BULK',
        bulkTankId: $bulk->id,
    )),
    InvalidArgumentException::class,
    'not in this company'
);

CurrentCompany::force(1);

/* ------------------------------------------------------------------------- */
echo "\n== cleanup ==\n";

$runIssueIds = $created['issue_ids'];
$runPurchaseIds = $created['purchase_ids'];
$runCostIds = $created['cost_ids'];
$runTripIds = $created['trip_ids'];
$runBulkTankIds = $created['bulk_tank_ids'];
$runMovementIds = $runBulkTankIds === [] ? [] : DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $runBulkTankIds)->pluck('id')->all();
$runEntries = fuelCostJournalEntries($runCostIds);
$runReceiptEntries = fuelSourceJournalEntries('fuel_purchase', $runPurchaseIds);
$runDipEntries = fuelSourceJournalEntries('bulk_tank_dip', $runMovementIds);
$allRunEntries = array_values(array_unique(array_merge($runEntries, $runReceiptEntries, $runDipEntries)));

purgeFuelJournalEntries($runEntries, 'verify-fuel: remove this run\'s posted entries');
purgeFuelJournalEntries($runReceiptEntries, 'verify-fuel: remove this run\'s stock receipts');
purgeFuelJournalEntries($runDipEntries, 'verify-fuel: remove this run\'s dip entries');

wipeFuelIds('fuel_issues', 'id', $runIssueIds);
wipeFuelIds('fuel_purchases', 'id', $runPurchaseIds);
wipeFuelIds('bulk_tank_movements', 'bulk_tank_id', $runBulkTankIds);
wipeFuelIds('bulk_tanks', 'id', $runBulkTankIds);
wipeFuelIds('fuel_tanks', 'id', $created['tank_ids']);
wipeFuelIds('trip_costs', 'trip_id', $runTripIds);

/*
 * A `trip_cost` entry whose cost row is gone is this run's own debris: every cost this file wrote was
 * deleted just above, and a cost whose id was never collected would leave the ledger naming a row that
 * does not exist — exactly what render-operations counts as a survivor. Scoped to entries this run
 * posted, so an earlier sweep's rows are not touched.
 */
$runOrphanEntries = DB::table('gl_journal_entries')
    ->where('source_type', 'trip_cost')
    ->where('created_at', '>=', $runStartedAt)
    ->whereRaw('`source_id` not in (select `id` from `trip_costs`)')
    ->pluck('id')
    ->all();
purgeFuelJournalEntries($runOrphanEntries, 'verify-fuel: entries whose cost this run already removed');
$runEntries = array_values(array_unique(array_merge($runEntries, $runOrphanEntries)));
$allRunEntries = array_values(array_unique(array_merge($allRunEntries, $runOrphanEntries)));
wipeFuelIds('loads', 'trip_id', $runTripIds);
wipeFuelIds('odometer_readings', 'trip_id', $runTripIds);
wipeFuelIds('odometer_readings', 'truck_id', $created['truck_ids']);
wipeFuelIds('vehicle_assignments', 'trip_id', $runTripIds);
wipeFuelIds('operational_events', 'aggregate_id', array_values(array_unique(array_merge($runTripIds, $runIssueIds))));
wipeFuelIds('operational_events', 'aggregate_id', $created['job_ids']);
wipeFuelIds('trips', 'id', $runTripIds);
wipeFuelIds('freight_jobs', 'id', $created['job_ids']);

DB::table('drivers')->whereIn('id', $created['driver_ids'] ?: [0])->delete();
DB::table('employees')->whereIn('id', $created['employee_ids'] ?: [0])->delete();
DB::table('trucks')->whereIn('id', $created['truck_ids'] ?: [0])->delete();
DB::table('fuel_stations')->where('name', 'like', 'XFL %')->delete();
DB::table('routes')->where('code', 'like', 'XFL%')->delete();
DB::table('customers')->where('code', 'like', 'XFL%')->delete();

foreach ([Trip::class, TripCost::class, FuelIssue::class, FuelPurchase::class, FuelStation::class, FuelTank::class] as $type) {
    DB::table('audits')->where('auditable_type', $type)->where('created_at', '>=', $runStartedAt)->delete();
}

$probeIds = DB::table('users')->where('email', 'like', 'verify-xfl-%@fleetguard.local')->pluck('id')->all();
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

/*
 * The §8 tenancy block inserts a second company by hand, and until now nothing removed it: the row
 * survived every run and the next `verify-operations` died on `1062 Duplicate entry '2' for key
 * companies.PRIMARY` before it could assert anything. A harness that leaves a company behind is a
 * harness that breaks its neighbours.
 */
DB::table('companies')->where('name', 'Verify XFL Second Company')->delete();

$alignCounters('after cleanup');

$afterExit = Artisan::call('documents:number-integrity');
$afterOutput = Artisan::output();
check($afterExit === 0, 'the counters and the surviving rows agree once the fixtures are gone', mb_substr(str_replace("\n", ' ', $afterOutput), 0, 200));

$leftovers = [
    'fuel_issues' => (int) DB::table('fuel_issues')->whereIn('id', $runIssueIds ?: ['0'])->count(),
    'fuel_purchases' => (int) DB::table('fuel_purchases')->whereIn('id', $runPurchaseIds ?: ['0'])->count(),
    'fuel_tanks' => (int) DB::table('fuel_tanks')->whereIn('truck_id', $created['truck_ids'] ?: [0])->count(),
    'fuel_stations' => (int) DB::table('fuel_stations')->where('name', 'like', 'XFL %')->count(),
    'trip_costs' => (int) DB::table('trip_costs')->whereIn('id', $runCostIds ?: ['0'])->count(),
    'bulk_tanks' => (int) DB::table('bulk_tanks')->whereIn('id', $runBulkTankIds ?: [0])->count(),
    'bulk tank movements' => (int) DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $runBulkTankIds ?: [0])->count(),
    'journal_entries' => (int) DB::table('gl_journal_entries')->whereIn('id', $allRunEntries ?: ['0'])->count(),
    'journal_lines' => (int) DB::table('gl_journal_lines')->whereIn('entry_id', $allRunEntries ?: ['0'])->count(),
    'trips' => (int) DB::table('trips')->whereIn('id', $runTripIds ?: ['0'])->count(),
    'freight_jobs' => (int) DB::table('freight_jobs')->whereIn('id', $created['job_ids'] ?: ['0'])->count(),
    'trucks' => (int) DB::table('trucks')->whereIn('id', $created['truck_ids'] ?: [0])->count(),
    'probe seats' => (int) DB::table('users')->where('email', 'like', 'verify-xfl-%@fleetguard.local')->count(),
];

foreach ($leftovers as $what => $count) {
    check($count === 0, "no {$what} survive this run", 'found '.$count);
}

check((int) DB::table('trucks')->count() >= 21, 'the demo fleet is intact: '.DB::table('trucks')->count().' trucks registered');
check(in_array(1, DB::table('users')->pluck('id')->all(), true), 'and the real accounts are still here');

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
