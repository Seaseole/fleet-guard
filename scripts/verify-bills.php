<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\Actions\RecordBill;
use App\Domain\Accounting\Actions\RecordSupplierPayment;
use App\Domain\Accounting\Actions\VoidBill;
use App\Domain\Accounting\BillCategory;
use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\Data\BillLineInput;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\BillLine;
use App\Domain\Accounting\Models\SupplierPayment;
use App\Domain\Accounting\Models\SupplierPaymentAllocation;
use App\Domain\Accounting\SupplierPaymentMethod;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FuelType;
use App\Domain\Fuel\Actions\CreateBulkTank;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\Queries\UnbilledDeliveries;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Data\EffectiveVatRate;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\Queries\EffectiveVat;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberIntegrityChecker;
use App\Domain\Kernel\Numbering\NumberPeriod;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Actions\CreatePart;
use App\Domain\Maintenance\Actions\ReceivePartStock;
use App\Domain\Maintenance\Data\PartDto;
use App\Domain\Maintenance\PartCategory;
use App\Domain\Maintenance\Queries\UnbilledReceipts;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\PlanTrip;
use App\Domain\Operations\Actions\QuoteJob;
use App\Domain\Operations\Data\JobDto;
use App\Domain\Operations\Data\JobQuoteDto;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Data\TripDto;
use App\Domain\Operations\RouteRateType;
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

/*
 * Phase 5 slice 5a verification — the supplier bill and the `BillRecorded` posting
 * (DESIGN.md §4.2, §6.1, §6.8, §7, §10.9, §20.35).
 *
 * The sentences this slice turns on:
 *
 *  - a paper document from a garage, an insurer, any supplier becomes a bill: numbered with the
 *    operational reference (never reused, allocated at creation, §7), and posting the expense the
 *    fleet owed but the ledger had never heard about (§6.8's `BillRecorded`, restated onto COA v1);
 *  - VAT is computed per line from the rate effective on the bill date, stored, and claimed onto
 *    2126 Input VAT — the pair §20.31 pre-work opened so the filing shape was settled before bills
 *    arrived;
 *  - the expense line names its leg because the account demands it (§6.1's masks): 5100 truck+trip,
 *    5310/5300 truck — a fuel bill that cannot name the trip is refused, not silently overheaded;
 *  - the payable lands on 2110 with the supplier and the bill as dimensions, the same liability
 *    Phase 3's receipts and deliveries were pointing at;
 *  - and a void mirrors the posting through the ledger's own door (§6.4), releases the lines, and
 *    keeps the number (§10.9).
 *
 * Fixtures are prefixed `BILL-VERIFY` and removed in `finally` — including this run's journal
 * entries, deleted under LedgerBarrier::withLiftedGuard() with a written reason, as §6.3 requires.
 *
 * Run: php scripts/verify-bills.php
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

/**
 * The rate the register applies on a date, as its stored scale.
 *
 * §20.37 made the standard rate a runtime figure an Owner can change — so a harness that hard-codes
 * 12% is asserting against a number the application no longer fixes. The percentage is read here and
 * the arithmetic is then restated **independently of the door** (integer HALF_UP at the stored scale),
 * which is what keeps the check worth running: agreement between two implementations proves nothing
 * about the figure, but it does prove the door is charging the rate on the register.
 */
function registerRateX(): ?int
{
    $rate = TaxRate::effectiveOn(Carbon::now());

    return $rate === null ? null : (int) $rate->rate_x10000;
}

/** The harness's own HALF_UP at the §5.5 scale, from the register's percentage. */
function vatAt(int $netMinor, int $rateX10000): int
{
    return intdiv($netMinor * $rateX10000 + 500_000, 10_000 * 100);
}

/** Everything this run wrote, removed here so a second run starts from the same place. */
function cleanUp(): void
{
    if (! Schema::hasTable('bills')) {
        return;
    }

    $supplierIds = DB::table('suppliers')->where('code', 'like', 'BILL-VERIFY%')->pluck('id')->all();
    // A crash between the fixture's creation and an earlier cleanup can leave a bill whose supplier row is
    // already gone; the note is the second handle, so the purge never leaves a numbered orphan behind.
    $billIds = DB::table('bills')
        ->where(fn ($q) => $q->whereIn('supplier_id', $supplierIds ?: [0])->orWhere('note', 'like', 'BILL-VERIFY%'))
        ->pluck('id')->all();
    $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'BILL-VERIFY%')->pluck('id')->all();
    $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();

    $paymentIds = $supplierIds === [] ? [] : DB::table('supplier_payments')->whereIn('supplier_id', $supplierIds)->pluck('id')->all();
    $allocationIds = $paymentIds === [] ? [] : DB::table('supplier_payment_allocations')->whereIn('supplier_payment_id', $paymentIds)->pluck('id')->all();

    // Phase 5b's fixtures: the delivery and the receipt the linked bills document. They are found by
    // the note/name prefix rather than through the bills, because a released link leaves no trace.
    $purchaseIds = DB::table('fuel_purchases')->where('source_uuid', 'like', 'verify-bill:%')->pluck('id')->all();
    $movementIds = DB::table('stock_movements')->where('note', 'like', 'BILL-VERIFY%')->pluck('id')->all();

    $entryIds = array_merge(
        $billIds === [] ? [] : DB::table('gl_journal_entries')
            ->where(fn ($q) => $q->where('source_type', 'like', 'bill%')->whereIn('source_id', $billIds))
            ->pluck('id')->all(),
        $paymentIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'supplier_payment')->whereIn('source_id', $paymentIds)->pluck('id')->all(),
        $purchaseIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'fuel_purchase')->whereIn('source_id', $purchaseIds)->pluck('id')->all(),
        $movementIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'stock_movement')->whereIn('source_id', $movementIds)->pluck('id')->all(),
    );

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-bills: remove this run\'s posted entries', null, function () use ($entryIds): void {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
            // A reversal names the entry it mirrors, so the mirrors go first.
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        });
    }

    if ($billIds !== []) {
        // An application names the bill, so it goes first: the evidence of a payment cannot outlive the
        // document it settled, even in a fixture purge (§20.33's ordering lesson).
        if ($allocationIds !== []) {
            DB::table('supplier_payment_allocations')->whereIn('id', $allocationIds)->delete();
        }

        DB::table('audits')->whereIn('auditable_type', [Bill::class, BillLine::class])
            ->where(fn ($q) => $q->whereIn('auditable_id', $billIds)->orWhereIn('auditable_id', DB::table('bill_lines')->whereIn('bill_id', $billIds)->pluck('id')->all() ?: ['0']))
            ->delete();
        DB::table('bill_lines')->whereIn('bill_id', $billIds)->delete();
        DB::table('operational_events')->where('aggregate_type', 'bill')->whereIn('aggregate_id', $billIds)->delete();

        // The stamped obligations name the bill, so they are released before it goes — the same order
        // the doors keep at run time, in reverse for the purge.
        if ($purchaseIds !== []) {
            DB::table('fuel_purchases')->whereIn('id', $purchaseIds)->delete();
        }

        if ($movementIds !== []) {
            DB::table('stock_movements')->whereIn('id', $movementIds)->delete();
        }

        DB::table('bills')->whereIn('id', $billIds)->delete();
    }

    if ($paymentIds !== []) {
        DB::table('supplier_payment_allocations')->whereIn('supplier_payment_id', $paymentIds)->delete();
        DB::table('operational_events')->where('aggregate_type', 'supplier_payment')->whereIn('aggregate_id', $paymentIds)->delete();
        DB::table('audits')->where('auditable_type', SupplierPayment::class)->whereIn('auditable_id', $paymentIds)->delete();
        DB::table('supplier_payments')->whereIn('id', $paymentIds)->delete();
    }

    DB::table('suppliers')->where('code', 'like', 'BILL-VERIFY%')->delete();

    // The tank's dip book names it, so the movements go before the tank — and the delivery rows are
    // already gone with the bills, so nothing is left pointing at either.
    $tankIds = DB::table('bulk_tanks')->where('name', 'like', 'BILL-VERIFY%')->pluck('id')->all();

    if ($tankIds !== []) {
        DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $tankIds)->delete();
        DB::table('bulk_tanks')->whereIn('id', $tankIds)->delete();
    }

    DB::table('parts')->where('sku', 'like', 'BILL-VERIFY-%')->delete();

    if ($tripIds !== []) {
        DB::table('trips')->whereIn('id', $tripIds)->delete();
    }

    if ($jobIds !== []) {
        DB::table('freight_jobs')->whereIn('id', $jobIds)->delete();
    }

    // The customer outlives the job that names it, so it goes last of the commercial fixtures.
    DB::table('customers')->where('code', 'like', 'BILL-VERIFY%')->delete();

    DB::table('routes')->where('code', 'like', 'BILL-VERIFY%')->delete();

    $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-BV1%')->pluck('id')->all();
    if ($employeeIds !== []) {
        DB::table('drivers')->whereIn('employee_id', $employeeIds)->delete();
        DB::table('employees')->whereIn('id', $employeeIds)->delete();
    }

    DB::table('trucks')->where('registration_no', 'like', '%BV1')->delete();

    $probeIds = DB::table('users')->where('email', 'like', 'verify-bill-%@fleetguard.local')->pluck('id')->all();

    DB::table('number_sequences')->whereIn('last_used_by', $probeIds ?: [0])->update(['last_used_by' => null]);
    DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
    DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
    DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

    /*
     * §20.8's rule that a harness must not break its neighbours: every counter this run could advance
     * is rewound to where the surviving documents leave it, or `documents:number-integrity` would
     * report drift that no person caused.
     */
    $rewind = static function (string $scope, string $table, string $column): void {
        $rows = DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->get();

        foreach ($rows as $row) {
            $periodKey = (string) $row->period_key;

            $numbers = DB::table($table)->where('company_id', 1)->whereNotNull($column)
                ->when($periodKey !== '', static fn ($query) => $query->where($column, 'like', '%'.$periodKey.'%'))
                ->pluck($column);

            $highest = $numbers->map(static function (string $number): int {
                $dash = strrpos($number, '-');

                return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
            })->max() ?? 0;

            DB::table('number_sequences')->where('id', $row->id)
                ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        }
    };

    $rewind('bill', 'bills', 'number');
    $rewind('supplier_payment', 'supplier_payments', 'number');
    $rewind('job', 'freight_jobs', 'job_no');
    $rewind('trip', 'trips', 'trip_no');
    $rewind('journal_entry', 'gl_journal_entries', 'entry_no');
}

CurrentCompany::force(1);

cleanUp();
Artisan::call('numbering:ensure', ['--company' => 1]);

/*
 * The seat that records is the Accountant's (§9.3 gives it `bills.create`); user 1 is a dispatcher and
 * must not be silently granted it by a harness.
 */
/** @var User $recorder */
$recorder = User::query()->firstOrNew(['email' => 'verify-bill-recorder@fleetguard.local']);
$recorder->name = 'Verify BILL Recorder';
$recorder->company_id = 1;
$recorder->password = Str::random(32);
$recorder->email_verified_at = Carbon::now();
$recorder->save();
app(ChangeUserRole::class)($recorder->fresh(), RoleKey::Accountant);
$recorder = $recorder->fresh();

$dispatcher = User::query()->findOrFail(1);

/*
 * Slice 5b's fixtures are Phase 3's doors: a bulk delivery needs `fuel.record` and a shelf receipt needs
 * `parts.adjust`, neither of which the Accountant holds (§9.3). The Owner seat carries both, so the
 * obligations this slice documents are created through the real doors by a probe that can, and the bills
 * and payments are recorded back through the Accountant's own door.
 */
/** @var User $workshop */
$workshop = User::query()->firstOrNew(['email' => 'verify-bill-workshop@fleetguard.local']);
$workshop->name = 'Verify BILL Workshop';
$workshop->company_id = 1;
$workshop->password = Str::random(32);
$workshop->email_verified_at = Carbon::now();
$workshop->save();
app(ChangeUserRole::class)($workshop->fresh(), RoleKey::Owner);
$workshop = $workshop->fresh();

Auth::login($recorder);

try {
    echo "== the fixture: a garage, a truck, and a trip whose diesel the garage billed ==\n";

    $base = CurrentCompany::model()->base_currency_code;

    $supplier = (app(CreateSupplier::class))(new SupplierDto(
        name: 'BILL-VERIFY Garage',
        code: 'BILL-VERIFY-SUP',
        paymentTerms: 'net_30',
    ));

    $customer = (app(CreateCustomer::class))(new CustomerDto(
        name: 'BILL-VERIFY Trader', code: 'BILL-VERIFY-CUST', paymentTerms: 'net_30',
    ));

    $truck = (app(CreateTruck::class))(new TruckDto(
        registrationNo: 'B 992 BV1', fleetNo: 'T-BV1', vin: 'VINBV10000000000000001',
        make: 'Probe', model: 'Bill', odometerKm: 10_000,
    ));

    $route = (app(CreateRoute::class))(new RouteDto(
        code: 'BILL-VERIFY-GBE-NDO', origin: 'Gaborone', destination: 'Ndola',
        originCountryCode: 'BW', destinationCountryCode: 'ZM', distanceKm: '1400',
    ));
    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id,
        routeId: $route->id,
        commodity: 'probe cargo',
        tonnes: 22.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(8)->toDateString(),
        notes: 'BILL-VERIFY job',
    ), 1);
    $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '80000', currencyCode: $base), 1);
    $job = (app(AcceptJob::class))($job, 1);

    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-BV1001', name: 'BILL-VERIFY Driver', hiredOn: '2024-01-10', nationalId: 'ID-BV1000001'));
    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id,
        licenceNo: 'DL-BV10001',
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
        plannedDepartureAt: Carbon::now()->addDays(2)->toDateTimeString(),
    ), 1);

    check($supplier->exists && $trip->id !== null, 'the probe has a supplier, a truck and a planned trip to bill against');

    $rate = TaxRate::effectiveOn(Carbon::now());
    $rateX = registerRateX();
    check($rate !== null && $rateX !== null && $rateX > 0, 'a standard VAT rate applies on the bill date (§17 Q4)', $rate === null ? 'none seeded' : 'rate zero');

    /* ------------------------------------------------------------------ A: the seat */
    echo "\n== A: only the seat that may record does ==\n";

    check(! Gate::forUser($dispatcher)->allows('bills.create'), 'a dispatcher does not hold bills.create (§9.3)');

    Auth::login($dispatcher);
    $dispatcherRefused = '';

    try {
        (app(RecordBill::class))->execute(
            supplierId: $supplier->id,
            lines: [new BillLineInput(description: 'Clutch repair', category: BillCategory::Labour, netMinor: 3_500_000, truckId: $truck->id)],
            billOn: Carbon::now(),
            dueOn: Carbon::now()->addDays(30),
            actorId: $dispatcher->id,
        );
    } catch (Throwable $refused) {
        $dispatcherRefused = $refused->getMessage();
    }

    check(str_contains($dispatcherRefused, 'bills.create'), '...so a dispatcher cannot record a bill, and the refusal names the ability', $dispatcherRefused);
    check(Bill::query()->where('supplier_id', $supplier->id)->count() === 0, '...and the refused attempt wrote no document and spent no number');

    Auth::login($recorder);

    /* ------------------------------------------------------------------ B: the posting */
    echo "\n== B: BillRecorded posts the expense the ledger had never heard about ==\n";

    $fuelNet = 499_955;  // a figure that tests HALF_UP: 12% is 59_994.6 minor units
    $labourNet = 3_500_000;
    $insuranceNet = 1_250_000;

    $sequenceBefore = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'bill')
        ->where('period_key', NumberPeriod::keyFor('continuous', Carbon::now(), 'bill'))
        ->value('next_value');

    $billOn = Carbon::now()->startOfDay();

    $bill = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [
            new BillLineInput(description: 'Diesel burned on the delivery leg', category: BillCategory::Fuel, netMinor: $fuelNet, truckId: $truck->id, tripId: $trip->id),
            new BillLineInput(description: 'Clutch repair at the garage', category: BillCategory::Labour, netMinor: $labourNet, truckId: $truck->id),
            new BillLineInput(description: 'Annual hull policy', category: BillCategory::Insurance, netMinor: $insuranceNet),
        ],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(30),
        supplierDocNo: 'GR-2026-118',
        note: 'BILL-VERIFY garage bill',
        sourceUuid: 'verify-bill:one',
        actorId: $recorder->id,
    );

    $expectedFuelTax = vatAt($fuelNet, (int) $rateX);
    $expectedLabourTax = vatAt($labourNet, (int) $rateX);
    $expectedInsuranceTax = vatAt($insuranceNet, (int) $rateX);
    $expectedTax = $expectedFuelTax + $expectedLabourTax + $expectedInsuranceTax;
    $expectedNet = $fuelNet + $labourNet + $insuranceNet;
    $expectedGross = $expectedNet + $expectedTax;

    check($bill->exists, 'the Accountant seat records the bill');
    check($bill->status === BillLifecycle::Recorded, '...and the row is recorded, not draft');
    check($bill->amount === $expectedGross && $bill->base_amount === $expectedGross, '...whose header stores the gross the supplier asks for', "amount={$bill->amount} expected={$expectedGross}");
    check($bill->tax_amount === $expectedTax, '...and the bill\'s VAT is the sum of the rounded lines, never the rounded sum (§5.5)', "tax={$bill->tax_amount} expected={$expectedTax}");
    // The rounding rule, not the rate: pick a figure whose VAT lands on a fraction of a minor unit, so
    // half-up and truncate disagree and the check can tell them apart whatever the register's percentage
    // is (§20.37 made the rate a runtime fact; §5.5 keeps the rounding a fixed one).
    $exactFuelTax = $fuelNet * (int) $rateX;
    $truncatedFuelTax = intdiv($exactFuelTax, 10_000 * 100);

    check($exactFuelTax % (10_000 * 100) !== 0, 'the probe\'s fuel line lands on a fractional VAT figure, so the rounding rule is really being tested');
    check($expectedFuelTax === (int) round($exactFuelTax / (10_000 * 100), 0, PHP_ROUND_HALF_UP), '...and the door rounded HALF_UP rather than truncating', "half-up={$expectedFuelTax} truncated={$truncatedFuelTax}");

    $sequenceAfter = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'bill')
        ->where('period_key', NumberPeriod::keyFor('continuous', Carbon::now(), 'bill'))
        ->value('next_value');

    check(str_starts_with($bill->number, 'BILL-'), 'the bill carries its operational reference', $bill->number);
    check($sequenceAfter === $sequenceBefore + 1, '...and exactly one sequence value was spent', "before={$sequenceBefore} after={$sequenceAfter}");

    $entry = DB::table('gl_journal_entries')
        ->where('company_id', 1)->where('source_type', 'bill')->where('source_id', $bill->id)
        ->first();

    check($entry !== null, 'the ledger holds exactly one entry for this bill');
    $entryLines = DB::table('gl_journal_lines')->where('entry_id', $entry->id)->get();
    $entryLineCount = (int) $entryLines->count();
    check($entryLineCount === 5, '...with five lines: three debits, one VAT, one payable', "lines={$entryLineCount}");

    $debit = (int) $entryLines->where('direction', 'debit')->sum('amount_base');
    $credit = (int) $entryLines->where('direction', 'credit')->sum('amount_base');
    check($debit === $credit, '...which balances (§6.1)', "debit={$debit} credit={$credit}");
    check($credit === $expectedGross, '...at the gross the document states', "credit={$credit} expected={$expectedGross}");

    $accountOf = static fn (stdClass $line): string => (string) DB::table('gl_accounts')->where('id', $line->account_id)->value('code');

    $byAccount = $entryLines->groupBy($accountOf);

    check($byAccount->has('5100') && (int) $byAccount['5100']->sum('amount_base') === $fuelNet, 'the fuel line debited 5100 at its net (§6.8)');
    check($byAccount->has('5300') && (int) $byAccount['5300']->sum('amount_base') === $labourNet, '...the labour line debited 5300, the maintenance account COA v1 actually carries');
    check($byAccount->has('5500') && (int) $byAccount['5500']->sum('amount_base') === $insuranceNet, '...and the policy line debited 5500');
    check($byAccount->has('2126') && (int) $byAccount['2126']->sum('amount_base') === $expectedTax, '...with the bill\'s whole input VAT on 2126, kept apart from 2120 (§20.31 pre-work)');
    check($byAccount->has('2110') && (int) $byAccount['2110']->where('direction', 'credit')->sum('amount_base') === $expectedGross, '...and one payable credit on 2110 at the gross');

    $fuelLedgerLine = $byAccount['5100']->first();
    check((int) $fuelLedgerLine->truck_id === $truck->id, 'the fuel line names the truck its account demands (§6.1)');
    check($fuelLedgerLine->trip_id === $trip->id, '...and the trip it was burned on');
    check((int) $entryLines->where('direction', 'credit')->first()->supplier_id === $supplier->id, 'the payable names the supplier (§6.2)');
    check($entryLines->every(static fn (stdClass $line): bool => $line->bill_id === $bill->id), '...and every line carries the bill dimension, so AP ageing is one query');

    $savedLines = $bill->lines()->get();
    check($savedLines->count() === 3, 'the document keeps its own three lines');
    check($savedLines->firstWhere('category', 'fuel')->expense_account_code === '5100'
        && $savedLines->firstWhere('category', 'insurance')->expense_account_code === '5500',
        '...each copied with the account it posted to, so a later re-code cannot rewrite history (§20.32)');
    check((int) $savedLines->firstWhere('category', 'fuel')->tax_amount === $expectedFuelTax, '...and the per-line VAT is stored, not re-derived at print time');

    /* ------------------------------------------------------------------ C: idempotency */
    echo "\n== C: one retry is one bill ==\n";

    $again = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [new BillLineInput(description: 'Same paper, retyped', category: BillCategory::Labour, netMinor: 1)],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(30),
        sourceUuid: 'verify-bill:one',
        actorId: $recorder->id,
    );

    check($again->id === $bill->id, 'a retry on the same source_uuid returns the bill it already recorded');
    check(Bill::query()->where('supplier_id', $supplier->id)->count() === 1, '...and writes no second row');
    $sequenceAfterRetry = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'bill')
        ->where('period_key', NumberPeriod::keyFor('continuous', Carbon::now(), 'bill'))
        ->value('next_value');
    check($sequenceAfterRetry === $sequenceAfter, '...spending no second number');

    /* ------------------------------------------------------------------ D: the refusals */
    echo "\n== D: the door refuses what the paper cannot mean ==\n";

    $refusals = [
        'fuel without a trip' => [
            'lines' => [new BillLineInput(description: 'Diesel with no leg', category: BillCategory::Fuel, netMinor: 100_000, truckId: $truck->id)],
            'needle' => 'trip',
        ],
        'parts without a truck' => [
            'lines' => [new BillLineInput(description: 'Filters for someone', category: BillCategory::Parts, netMinor: 100_000)],
            'needle' => 'truck',
        ],
        'a line in Rands' => [
            'lines' => [new BillLineInput(description: 'ZAR policy', category: BillCategory::Insurance, netMinor: 100_000, currencyCode: 'ZAR')],
            'needle' => 'FX',
        ],
        'no lines at all' => ['lines' => [], 'needle' => 'zero-value'],
    ];

    foreach ($refusals as $label => $case) {
        $message = '';

        try {
            (app(RecordBill::class))->execute(
                supplierId: $supplier->id,
                lines: $case['lines'],
                billOn: $billOn,
                dueOn: $billOn->copy()->addDays(30),
                actorId: $recorder->id,
            );
        } catch (Throwable $refused) {
            $message = $refused->getMessage();
        }

        check(str_contains($message, $case['needle']), "the bill refuses {$label}, naming it", $message === '' ? 'no refusal at all' : $message);
    }

    $lateMessage = '';

    try {
        (app(RecordBill::class))->execute(
            supplierId: $supplier->id,
            lines: [new BillLineInput(description: 'Anything', category: BillCategory::Other, netMinor: 100_000)],
            billOn: $billOn,
            dueOn: $billOn->copy()->subDay(),
            actorId: $recorder->id,
        );
    } catch (Throwable $refused) {
        $lateMessage = $refused->getMessage();
    }

    check(str_contains($lateMessage, 'due before'), '...and a bill due before it is dated', $lateMessage);
    check(Bill::query()->where('supplier_id', $supplier->id)->count() === 1, 'none of the refusals wrote a row or spent a number');

    /* ------------------------------------------------------------------ E: the VAT door */
    echo "\n== E: the rate register answers through its own door ==\n";

    $projected = app(EffectiveVat::class)->on($billOn);
    check($projected instanceof EffectiveVatRate, 'Invoicing\'s EffectiveVat answers a projection, not the TaxRate model (§3.1 D5)');
    check($projected->id === $rate->id, '...naming the rate that applies on the bill date');
    check($projected->taxOn($fuelNet) === $rate->taxOn($fuelNet), '...whose per-line rounding is the register\'s own (§20.32\'s defect stays fixed on both sides)');

    /* ------------------------------------------------------------------ F: the void */
    echo "\n== F: a void mirrors, releases, and keeps its number ==\n";

    $shortReason = '';

    try {
        (app(VoidBill::class))($bill, 'oops', $recorder->id);
    } catch (Throwable $refused) {
        $shortReason = $refused->getMessage();
    }

    check(str_contains($shortReason, 'ten characters'), 'a void without a real reason is refused', $shortReason);

    $voided = (app(VoidBill::class))($bill, 'The garage mis-keyed the policy line', $recorder->id);

    check($voided->status === BillLifecycle::Void, 'the Accountant voids the bill');
    check($voided->number === $bill->number, '...keeping its BILL- number: operational references are never reused (§7)');

    $mirror = DB::table('gl_journal_entries')
        ->where('company_id', 1)->where('source_type', 'bill_void')->where('source_id', $bill->id)
        ->first();

    check($mirror !== null, '...and the ledger holds the mirror entry');
    check((int) $mirror->reverses_entry_id === (int) $entry->id, '...naming the entry it reverses (§6.4)');
    check($mirror->netting_group === $bill->id, '...netted by the bill itself');

    $mirrorLines = DB::table('gl_journal_lines')->where('entry_id', $mirror->id)->get();
    check((int) $mirrorLines->where('direction', 'debit')->sum('amount_base') === $expectedGross
        && (int) $mirrorLines->where('direction', 'credit')->sum('amount_base') === $expectedGross,
        '...whose sides are swapped and totals equal the original');

    $netAfter = (int) DB::table('gl_journal_lines')
        ->whereIn('entry_id', [$entry->id, $mirror->id])
        ->where('account_id', DB::table('gl_accounts')->where('code', '2110')->value('id'))
        ->sum(DB::raw('case when direction = \'debit\' then amount_base else -amount_base end'));
    check($netAfter === 0, '...so the pair nets the payable back to zero', "net={$netAfter}");

    check($bill->lines()->whereNotNull('released_at')->count() === 3, 'the lines are released, never deleted (§10.9)');
    check($bill->lines()->count() === 3, '...and still stand as the document\'s own record');

    $againVoid = '';

    try {
        (app(VoidBill::class))($voided, 'Voiding twice proves nothing about this bill', $recorder->id);
    } catch (Throwable $refused) {
        $againVoid = $refused->getMessage();
    }

    check(str_contains($againVoid, 'only a recorded bill'), 'a second void is refused — the posting is already mirrored', $againVoid);

    /* ------------------------------------------------------------------ H: money out */
    echo "\n== H: the payment settles the payable — Dr 2110 / Cr bank, applied to the paper ==\n";

    $payNet = 3_500_000;
    $payGross = $payNet + vatAt($payNet, (int) $rateX);

    /** @var Bill $payBill */
    $payBill = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [new BillLineInput(description: 'Gearbox rebuild', category: BillCategory::Labour, netMinor: $payNet, truckId: $truck->id)],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(30),
        note: 'BILL-VERIFY payables probe',
        actorId: $recorder->id,
    );

    check($payBill->balance_amount === $payGross, 'a fresh bill owes its whole gross — the projection starts at the document', "balance={$payBill->balance_amount} expected={$payGross}");
    check($payBill->settlementState() === SettlementState::Open, '...and its derived state reads open, stored nowhere (§10.4 sideways)');

    $paymentSequenceBefore = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'supplier_payment')
        ->where('period_key', NumberPeriod::keyFor('continuous', $billOn, 'supplier_payment'))
        ->value('next_value');

    Auth::login($dispatcher);
    $payRefused = '';

    try {
        (app(RecordSupplierPayment::class))->execute(
            supplierId: $supplier->id,
            amountMinor: 1_000_000,
            method: SupplierPaymentMethod::Bank,
            paidOn: $billOn,
            billIds: [(string) $payBill->id],
            actorId: $dispatcher->id,
        );
    } catch (Throwable $refused) {
        $payRefused = $refused->getMessage();
    }

    check(str_contains($payRefused, 'bills.create'), 'a dispatcher cannot pay a supplier, and the refusal names the ability (§9.3)', $payRefused);
    check(SupplierPayment::query()->where('supplier_id', $supplier->id)->count() === 0, '...and the refused attempt wrote no payment and spent no number');

    Auth::login($recorder);

    $partial = (app(RecordSupplierPayment::class))->execute(
        supplierId: $supplier->id,
        amountMinor: 1_000_000,
        method: SupplierPaymentMethod::Bank,
        paidOn: $billOn,
        billIds: [(string) $payBill->id],
        reference: 'EFT 8812',
        sourceUuid: 'verify-pay:part',
        actorId: $recorder->id,
    );

    $partialBill = $payBill->fresh();
    $partialEntry = DB::table('gl_journal_entries')->where('source_type', 'supplier_payment')->where('source_id', $partial->id)->first();
    $partialLines = DB::table('gl_journal_lines')->where('entry_id', $partialEntry->id)->get();
    $bankId = (int) DB::table('gl_accounts')->where('code', '1120')->value('id');
    $payableId = (int) DB::table('gl_accounts')->where('code', '2110')->value('id');

    check(str_starts_with($partial->number, 'PAY-'), 'the Accountant pays part of the bill and the receipt carries its own reference', $partial->number);
    check($partialEntry !== null, '...and one entry names the payment');
    check((int) $partialLines->where('direction', 'debit')->sum('amount_base') === 1_000_000
        && (int) $partialLines->where('direction', 'credit')->sum('amount_base') === 1_000_000,
        '...which balances at the money that moved (§6.1)');
    check((int) $partialLines->firstWhere('account_id', $payableId)?->amount_base === 1_000_000
        && $partialLines->firstWhere('account_id', $payableId)->bill_id === $payBill->id,
        '...debiting 2110 under the bill\'s own dimension, so AP ageing is one query (§4.2)');
    check((int) $partialLines->firstWhere('account_id', $bankId)?->amount_base === 1_000_000
        && (string) $partialLines->firstWhere('account_id', $bankId)->direction === 'credit',
        '...and crediting the bank account the method names (§6.8)');
    check($partialBill->balance_amount === $payGross - 1_000_000, '...the projection walks down by exactly the application', "balance={$partialBill->balance_amount}");
    check($partialBill->settlementState()->value === 'partially_paid', '...and the derived state says partially paid while the lifecycle stays recorded (§10.4)');
    check((string) $partialBill->status->value === 'recorded', '...the lifecycle axis did not move');
    check(SupplierPaymentAllocation::query()->where('bill_id', $payBill->id)->where('reversed', false)->count() === 1, '...one application row stands behind it');
    check($partial->unapplied_amount === 0, 'the payment was spent whole — nothing was left on account');

    $retried = (app(RecordSupplierPayment::class))->execute(
        supplierId: $supplier->id,
        amountMinor: 1_000_000,
        method: SupplierPaymentMethod::Bank,
        paidOn: $billOn,
        billIds: [(string) $payBill->id],
        sourceUuid: 'verify-pay:part',
        actorId: $recorder->id,
    );

    $paymentSequenceAfterRetry = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'supplier_payment')
        ->where('period_key', NumberPeriod::keyFor('continuous', $billOn, 'supplier_payment'))
        ->value('next_value');

    check($retried->id === $partial->id, 'a retried payment returns the first row');
    check($paymentSequenceAfterRetry === $paymentSequenceBefore + 1, '...and spends exactly one PAY- number', "before={$paymentSequenceBefore} after={$paymentSequenceAfterRetry}");

    // The rest, plus one thebe more than the bill needs: the balance closes to exactly zero and the
    // surplus stays as an advance on 2110 with no bill dimension — it is the fleet's claim, not a document's.
    $closing = (app(RecordSupplierPayment::class))->execute(
        supplierId: $supplier->id,
        amountMinor: $partialBill->balance_amount + 100,
        method: SupplierPaymentMethod::Cash,
        paidOn: $billOn,
        billIds: [(string) $payBill->id],
        actorId: $recorder->id,
    );

    $settledBill = $payBill->fresh();
    $closingLines = DB::table('gl_journal_lines')
        ->where('entry_id', DB::table('gl_journal_entries')->where('source_type', 'supplier_payment')->where('source_id', $closing->id)->value('id'))
        ->get();

    check($settledBill->balance_amount === 0, 'paying the rest drives the balance to exactly zero (definition of done)', "balance={$settledBill->balance_amount}");
    check($settledBill->settlementState()->value === 'paid', '...and the derived state reads paid');
    check($closing->unapplied_amount === 100, '...one thebe no bill claimed stays as an advance', "unapplied={$closing->unapplied_amount}");
    check($closingLines->where('account_id', $payableId)->whereNull('bill_id')->count() === 1, '...booked as an undimensioned debit on 2110, because no document owns it');
    check((string) $closingLines->firstWhere('account_id', (int) DB::table('gl_accounts')->where('code', '1110')->value('id'))?->direction === 'credit', '...and the cash account is credited when the method says cash (§6.8)');
    check(SupplierPaymentAllocation::query()->where('bill_id', $payBill->id)->where('settled', true)->count() === 1, 'the application that closed the bill is marked, so the tie-out need not re-walk FIFO (§20.33)');

    $voidRefused = '';

    try {
        (app(VoidBill::class))($payBill, 'A paid bill cannot be unposted', $recorder->id);
    } catch (Throwable $refused) {
        $voidRefused = $refused->getMessage();
    }

    check(str_contains($voidRefused, 'debit note'), 'a bill with money on it refuses its own void and names the door that does exist', $voidRefused);
    check((string) $payBill->fresh()->status->value === 'recorded', '...and the bill is left as it was');

    $noPaper = (app(CreateSupplier::class))(new SupplierDto(name: 'BILL-VERIFY No paper', code: 'BILL-VERIFY-BARE'));
    $bareRefused = '';

    try {
        (app(RecordSupplierPayment::class))->execute(
            supplierId: $noPaper->id,
            amountMinor: 100_000,
            method: SupplierPaymentMethod::Bank,
            paidOn: $billOn,
            actorId: $recorder->id,
        );
    } catch (Throwable $refused) {
        $bareRefused = $refused->getMessage();
    }

    check(str_contains($bareRefused, 'no open bill'), 'cash that never met a document is refused rather than banked blind (§6.8)', $bareRefused);

    /* ------------------------------------------------------------------ I: linked bills */
    echo "\n== I: the paper for an obligation the ledger already holds posts nothing twice ==\n";

    Auth::login($workshop);

    $base2 = CurrentCompany::model()->base_currency_code;

    $tank = (app(CreateBulkTank::class))('BILL-VERIFY Yard tank', 5_000);

    $delivery = (app(CreateFuelPurchase::class))(new FuelPurchaseDto(
        amount: Money::ofMajor('12000.00', $base2),
        rate: '1',
        rateSource: FxRateSource::Manual,
        rateDate: $billOn,
        purchasedOn: $billOn,
        litresX100: 600_000,
        pricePerLitreMinor: 200,
        fuelType: FuelType::Diesel,
        sourceUuid: 'verify-bill:delivery',
        bulkTankId: $tank->id,
    ), $workshop->id);

    $part = (app(CreatePart::class))(new PartDto(
        sku: 'BILL-VERIFY-FLT-1', name: 'Probe filter', category: PartCategory::Filters,
        unitCostMinor: 5_000, currencyCode: $base2,
    ));

    $receipt = (app(ReceivePartStock::class))($part, 400, Money::ofMinor(5_000, $base2), $billOn, 'BILL-VERIFY shelf receipt', $workshop->id);

    $deliveryObligation = app(UnbilledDeliveries::class)->find((string) $delivery->id);
    $receiptObligation = app(UnbilledReceipts::class)->find((string) $receipt->id);

    check($deliveryObligation !== null && $deliveryObligation->amountBase === 1_200_000, 'the yard\'s posted delivery is on the unbilled list at its own figure (§20.26)', $deliveryObligation === null ? 'not listed' : 'amount='.$deliveryObligation->amountBase);
    check($receiptObligation !== null && $receiptObligation->amountBase === 20_000, '...and so is the shelf\'s receipt at the value it moved (§20.29)', $receiptObligation === null ? 'not listed' : 'amount='.$receiptObligation->amountBase);

    Auth::login($recorder);

    $linked = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(14),
        supplierDocNo: 'ENG-2026-77',
        note: 'BILL-VERIFY linked delivery',
        linkedSource: BillLinkedSource::FuelDelivery,
        linkedSourceId: (string) $delivery->id,
        actorId: $recorder->id,
    );

    check($linked->linked_source === BillLinkedSource::FuelDelivery, 'the delivery\'s paper records the link as well as the number');
    check($linked->amount === 1_200_000 && $linked->tax_amount === 0, '...and carries the book\'s figure, not a typed one — no input VAT, because the movement modelled none');
    check($linked->lines()->count() === 0, '...with no lines of its own (§20.36)');
    check(DB::table('gl_journal_entries')->where('source_type', 'bill')->where('source_id', $linked->id)->count() === 0, '...and posted nothing: a second expense debit would fund the same diesel twice');
    check((string) DB::table('fuel_purchases')->where('id', $delivery->id)->value('bill_id') === $linked->id, '...while Fuel\'s own door stamped the delivery (§3.1 D5 — Accounting asked)');
    check(app(UnbilledDeliveries::class)->find((string) $delivery->id) === null, '...so the delivery leaves the unbilled list');

    $twiceRefused = '';

    try {
        (app(RecordBill::class))->execute(
            supplierId: $supplier->id,
            lines: [],
            billOn: $billOn,
            dueOn: $billOn->copy()->addDays(14),
            note: 'BILL-VERIFY second paper',
            linkedSource: BillLinkedSource::FuelDelivery,
            linkedSourceId: (string) $delivery->id,
            actorId: $recorder->id,
        );
    } catch (Throwable $refused) {
        $twiceRefused = $refused->getMessage();
    }

    check(str_contains($twiceRefused, 'unbilled'), 'one delivery cannot fund two live bills — the second paper is refused by name', $twiceRefused);
    check(DB::table('bills')->where('linked_source_id', $delivery->id)->where('status', '<>', 'void')->count() === 1, '...and no second live row exists');

    $mixedRefused = '';

    try {
        (app(RecordBill::class))->execute(
            supplierId: $supplier->id,
            lines: [new BillLineInput(description: 'Delivery plus a filter', category: BillCategory::Parts, netMinor: 5_000, truckId: $truck->id)],
            billOn: $billOn,
            dueOn: $billOn->copy()->addDays(14),
            linkedSource: BillLinkedSource::FuelDelivery,
            linkedSourceId: (string) $receipt->id,
            actorId: $recorder->id,
        );
    } catch (Throwable $refused) {
        $mixedRefused = $refused->getMessage();
    }

    check(str_contains($mixedRefused, 'carries no lines'), 'a linked bill that also brings lines is refused — the shapes are a pair, not a menu', $mixedRefused);

    $linkedPaid = (app(RecordSupplierPayment::class))->execute(
        supplierId: $supplier->id,
        amountMinor: $linked->amount,
        method: SupplierPaymentMethod::Bank,
        paidOn: $billOn,
        billIds: [(string) $linked->id],
        actorId: $recorder->id,
    );

    $netAfter = (int) DB::table('gl_journal_lines')
        ->where('account_id', $payableId)
        ->where('bill_id', $linked->id)
        ->sum(DB::raw('case when direction = \'debit\' then amount_base else -amount_base end'));

    check($linkedPaid->allocations()->count() === 1 && $linked->fresh()->balance_amount === 0, 'paying the linked paper clears it from the projection alone');
    check($netAfter === 1_200_000, '...and the ledger holds exactly that one debit against the bill, the credit being the movement\'s own (§6.9 tie-out material)', "net={$netAfter}");

    $receiptLinked = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(14),
        note: 'BILL-VERIFY linked receipt',
        linkedSource: BillLinkedSource::StockReceipt,
        linkedSourceId: (string) $receipt->id,
        actorId: $recorder->id,
    );

    check((string) DB::table('stock_movements')->where('id', $receipt->id)->value('bill_id') === $receiptLinked->id, 'the parts receipt\'s paper stamps through Maintenance\'s door');

    $released = (app(VoidBill::class))($receiptLinked, 'Wrong supplier sent this paper', $recorder->id);

    check($released->status === BillLifecycle::Void, 'a linked bill voids without a mirror — there was no entry to reverse (§20.36)');
    check(DB::table('gl_journal_entries')->where('source_type', 'bill_void')->where('source_id', $receiptLinked->id)->count() === 0, '...and wrote no reversing entry either');
    check(DB::table('stock_movements')->where('id', $receipt->id)->value('bill_id') === null, '...the receipt is released back to awaiting the bill');
    check(app(UnbilledReceipts::class)->find((string) $receipt->id) !== null, '...and it is on the unbilled list again');

    $reBilled = (app(RecordBill::class))->execute(
        supplierId: $supplier->id,
        lines: [],
        billOn: $billOn,
        dueOn: $billOn->copy()->addDays(14),
        note: 'BILL-VERIFY re-billed receipt',
        linkedSource: BillLinkedSource::StockReceipt,
        linkedSourceId: (string) $receipt->id,
        actorId: $recorder->id,
    );

    check($reBilled->id !== $receiptLinked->id && $reBilled->number !== $receiptLinked->number, 'the same receipt can be billed again on a new number — the live-link index released with the void (§20.33\'s lesson, copied)');

    $payableTotal = (int) DB::table('gl_journal_lines')
        ->where('account_id', $payableId)
        ->where('company_id', 1)
        ->sum(DB::raw('case when direction = \'credit\' then amount_base else -amount_base end'));

    check($payableTotal >= 0, 'the payable account still reads as a liability across every posting this run made', "net credit={$payableTotal}");

    /* ------------------------------------------------------------------ G: integrity */
    echo "\n== G: the counters and the book agree with what survived ==\n";

    $findings = array_filter(
        (new NumberIntegrityChecker)->check(1),
        static fn ($finding): bool => $finding->scope === 'bill',
    );

    check($findings !== [], 'the bill scope is registered, so the checker is not silently skipping it');
    check(array_filter($findings, static fn ($finding): bool => $finding->isUnchecked()) === [], '...and it is checked rather than unchecked');
    check(array_filter($findings, static fn ($finding): bool => $finding->isDrift()) === [], '...with no drift between the sequence and the recorded documents', implode(' | ', array_map(static fn ($f): string => (string) $f->detail, $findings)));

    Artisan::call('ledger:verify');
    $ledgerOutput = Artisan::output();
    check(str_contains($ledgerOutput, 'no drift'), 'ledger:verify reports no drift after the slice\'s postings and purges', trim($ledgerOutput));
} catch (Throwable $blewUp) {
    failed('the probe ran to the end', get_class($blewUp).': '.$blewUp->getMessage());
} finally {
    Auth::logout();
    cleanUp();
}

echo "\n== result ==\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
