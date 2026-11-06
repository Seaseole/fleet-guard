<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\AllocatePayment;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\CreateManualInvoice;
use App\Domain\Invoicing\Actions\DiscardManualInvoice;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\IssueManualInvoice;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Data\ManualInvoiceDto;
use App\Domain\Invoicing\Data\ManualInvoiceLineDto;
use App\Domain\Invoicing\Data\PaymentDto;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Models\PaymentAllocation;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\RateResolver;
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
use App\Domain\Operations\Models\Trip;
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
use Illuminate\Support\Str;

/**
 * Phase 4 completion verification — the currency, the hand-raised document and the deposit.
 *
 * Three doors that Phase 4 left shut are opened here and each is proven from the row up: a **corridor
 * invoice** is issued in the currency its chargeables were priced in, carrying the rate those frozen
 * figures imply; a **manual document** exists as a draft with no number and no posting
 * until the fiscal act, and is discarded while it is still only paper; a **deposit** is banked
 * against a customer with no invoice named, in either currency. Then the seam the design has
 * been holding since **realised FX** at allocation, where the receipt's own rate meets the
 * invoice's own and the gap is named on 7510 or 7511 rather than hidden in a rounded cent.
 *
 * The residue rule is checked where it now lives — the last line of a foreign document absorbs the
 * rounding difference of the conversion, and the last units of a foreign receipt absorb it at
 * settlement, so both the document and the receipt close at exactly zero.
 *
 * Everything created is named FXV and removed at the end through the ledger barrier, and the two
 * fiscal sequences are rewound to the highest number still on disk, so the run leaves no gap.
 *
 * Run: php scripts/verify-fx-invoicing.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness posts to the live ledger. Dev only.\n";
    exit(2);
}

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  ok   {$what}\n";
}

function check(bool $condition, string $what, ?string $detail = null): void
{
    $condition ? ok($what) : fail($what, $detail ?? 'the assertion did not hold');
}

function fail(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

/** Asserts a callable threw, and (optionally) that the refusal says a particular thing. */
function refuses(string $what, callable $fn, string $expectIn = ''): void
{
    global $checks, $failures;
    $checks++;

    try {
        $fn();
        $failures++;
        echo "  FAIL {$what} — nothing refused it\n";
    } catch (Throwable $e) {
        if ($expectIn !== '' && ! str_contains($e->getMessage(), $expectIn)) {
            $failures++;
            echo "  FAIL {$what} — refused, but not for the stated reason: {$e->getMessage()}\n";

            return;
        }

        ok("{$what} (refused: {$e->getMessage()})");
    }
}

CurrentCompany::force(1);
Artisan::call('numbering:ensure', ['--company' => 1]);

/** @var User $issuer */
$issuer = User::query()->firstOrNew(['email' => 'verify-fxv-issuer@fleetguard.local']);
$issuer->name = 'Verify FXV Issuer';
$issuer->company_id = 1;
$issuer->password = Str::random(32);
$issuer->email_verified_at = Carbon::now();
$issuer->save();
app(ChangeUserRole::class)($issuer->fresh(), RoleKey::Accountant);
$issuer = $issuer->fresh();

Auth::login($issuer);

$base = CurrentCompany::model()->base_currency_code;
$foreign = $base === 'ZAR' ? 'BWP' : 'ZAR';
$taxRate = TaxRate::effectiveOn(Carbon::now());

$invoiceIds = [];
$paymentIds = [];
$customerIds = [];
$tripIds = [];

/*
 * The sweep is written once and run twice: up front, so a run that died halfway cannot block the
 * next one on a unique registration, and at the end, so this run leaves nothing behind. It finds its
 * own rows by the FXV marker rather than trusting a list, because the list only exists if the run
 * finished far enough to build one — a proof that cannot be re-run is not a proof.
 */
$sweep = function (array $extraInvoices = [], array $extraPayments = []) use ($issuer): void {
    $sweepCustomers = DB::table('customers')->where('code', 'like', 'FXV-%')->pluck('id')->all();

    $sweepInvoices = array_values(array_unique(array_merge(
        $sweepCustomers === [] ? [] : DB::table('invoices')->whereIn('customer_id', $sweepCustomers)->pluck('id')->all(),
        $extraInvoices,
    )));

    $sweepPayments = array_values(array_unique(array_filter(array_merge(
        $sweepCustomers === [] ? [] : DB::table('payments')->whereIn('customer_id', $sweepCustomers)->pluck('id')->all(),
        $extraPayments,
    ), static fn ($id): bool => $id !== null && $id !== '')));

    $sweepTrips = DB::table('chargeables')->where('description', 'like', 'FXV %')->pluck('trip_id')->filter()->unique()->values()->all();

    LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', $issuer->id, function () use ($sweepInvoices, $sweepPayments, $sweepCustomers, $sweepTrips): void {
        $allocationIds = $sweepInvoices === [] && $sweepPayments === []
            ? []
            : DB::table('payment_allocations')
                ->whereIn('invoice_id', $sweepInvoices)
                ->orWhereIn('payment_id', $sweepPayments)
                ->pluck('id')->all();

        if ($allocationIds !== []) {
            $entryIds = DB::table('gl_journal_entries')->where('source_type', 'payment_allocation')->whereIn('source_id', $allocationIds)->pluck('id')->all();

            if ($entryIds !== []) {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            }

            DB::table('payment_allocations')->whereIn('id', $allocationIds)->delete();
        }

        if ($sweepInvoices !== []) {
            $entryIds = DB::table('gl_journal_entries')->where('source_type', 'invoice')->whereIn('source_id', $sweepInvoices)->pluck('id')->all();

            if ($entryIds !== []) {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            }

            $noteIds = DB::table('credit_notes')->whereIn('invoice_id', $sweepInvoices)->pluck('id')->all();

            if ($noteIds !== []) {
                DB::table('credit_note_lines')->whereIn('credit_note_id', $noteIds)->delete();
                DB::table('credit_notes')->whereIn('id', $noteIds)->delete();
            }

            DB::table('invoice_lines')->whereIn('invoice_id', $sweepInvoices)->delete();
            DB::table('invoices')->whereIn('id', $sweepInvoices)->delete();
        }

        if ($sweepPayments !== []) {
            $entryIds = DB::table('gl_journal_entries')->where('source_type', 'payment')->whereIn('source_id', $sweepPayments)->pluck('id')->all();

            if ($entryIds !== []) {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            }

            DB::table('payments')->whereIn('id', $sweepPayments)->delete();
        }

        DB::table('operational_events')->whereIn('aggregate_id', array_merge($sweepInvoices, $sweepPayments, $sweepTrips))->delete();
        DB::table('chargeables')->where('description', 'like', 'FXV %')->delete();

        if ($sweepTrips !== []) {
            DB::table('trips')->whereIn('id', $sweepTrips)->delete();
        }

        DB::table('freight_jobs')->where('notes', 'like', 'FXV job%')->delete();
        DB::table('routes')->where('code', 'FXV-BW-NA')->delete();

        if ($sweepCustomers !== []) {
            DB::table('customers')->whereIn('id', $sweepCustomers)->delete();
        }

        // Master data goes in the order its own keys demand: a driver hangs off an employee — and
        // stores only the masked licence, so it is found through its employee — a trip named a
        // truck, and a job named a route.
        $employeeIds = DB::table('employees')->where('employee_no', 'EMP-FX1001')->pluck('id')->all();

        if ($employeeIds !== []) {
            DB::table('drivers')->whereIn('employee_id', $employeeIds)->delete();
        }

        DB::table('employees')->where('employee_no', 'EMP-FX1001')->delete();
        DB::table('trucks')->where('registration_no', 'B 991 FX1')->delete();
        DB::table('trailers')->where('registration_no', 'T 991 FX1')->delete();
    });

    // A sequence is gap-free, so numbers this run spent are handed back by winding the counter
    // to the highest document still on disk rather than leaving phantom gaps in the register.
    foreach ([['invoice', 'invoices', 'number'], ['receipt', 'payments', 'number']] as [$scope, $table, $column]) {
        foreach (DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->get(['id', 'period_key']) as $sequence) {
            $highest = DB::table($table)->where('company_id', 1)->where('number', 'like', '%'.$sequence->period_key.'%')
                ->pluck($column)
                ->map(static function (?string $number): int {
                    if ($number === null) {
                        return 0;
                    }

                    $dash = strrpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash + 1));
                })
                ->max() ?? 0;

            DB::table('number_sequences')->where('id', $sequence->id)->update(['next_value' => $highest + 1]);
        }
    }
};

// The harness seat is left in place on purpose: `number_sequences.last_used_by` and
// `gl_ledger_barrier.lifted_by` both point at it, and un-pointing a legitimate audit row to erase a
// dev-only user would be the harness editing the books.
$sweep();

try {
    /* ------------------------------------------------------------------ the fixture */
    echo "== the fixture: one customer, and a delivered trip priced across a border ==\n";

    $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'FXV Corridor Freight', code: 'FXV-CUST-1', paymentTerms: 'net_30'));
    $customerIds[] = $customer->id;

    /** A second party, so a draft discarded here cannot be confused with a document issued elsewhere. */
    $other = (app(CreateCustomer::class))(new CustomerDto(name: 'FXV Sundry Buyer', code: 'FXV-CUST-2', paymentTerms: 'cash'));
    $customerIds[] = $other->id;

    $route = (app(CreateRoute::class))(new RouteDto(
        code: 'FXV-BW-NA', origin: 'Gaborone', destination: 'Windhoek',
        originCountryCode: 'BW', destinationCountryCode: 'NA', distanceKm: '750',
    ));

    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id,
        routeId: $route->id,
        commodity: 'fxv probe cargo',
        tonnes: 12.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
        notes: 'FXV job',
    ), $issuer->id);

    $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '10000', currencyCode: $foreign), $issuer->id);
    $job = (app(AcceptJob::class))($job, $issuer->id);

    $truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 991 FX1', fleetNo: 'T-FX1', vin: 'VINFX1000000000000001', make: 'Probe', model: 'Corridor', odometerKm: 10_000));
    $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 991 FX1', type: 'flatbed', capacityTonnes: 30.0));
    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-FX1001', name: 'FXV Driver', hiredOn: '2024-01-10', nationalId: 'ID-FX1000001'));
    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id,
        licenceNo: 'DL-FX10001',
        licenceCode: 'EC',
        licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
        allowanceRateBase: 30_000,
        kioskEnabled: true,
    ));

    $trip = (app(CreateTrip::class))($job, $issuer->id);
    $trip = (app(PlanTrip::class))($trip, new TripDto(
        jobId: $job->id,
        truckId: $truck->id,
        driverId: $driver->id,
        trailerId: $trailer->id,
        plannedDepartureAt: Carbon::now()->addDays(2)->toDateTimeString(),
    ), $issuer->id);
    $tripIds[] = $trip->id;

    /*
     * The two charges are written in the currency the quote named, each with its OWN base figure —
     * 10 000.00 {foreign} booked at 5.5 and 2 500.00 booked at 5.6. Different rates on purpose: the
     * invoice's derived rate must be the blend the frozen figures imply (5.52), never a rate read
     * from a feed on the day of posting.
     */
    foreach ([
        ['freight', ChargeableType::Freight, ChargeableBasis::PerTrip, 'FXV job · probe cargo', 1_000_000, 5_500_000, 'fxv:one'],
        ['detention', ChargeableType::Detention, ChargeableBasis::Fixed, 'FXV waiting time at the border', 250_000, 1_400_000, 'fxv:two'],
    ] as [$key, $type, $basis, $description, $amount, $baseAmount, $source]) {
        Chargeable::create([
            'id' => (string) Str::ulid(),
            'trip_id' => $trip->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => $type->value,
            'basis' => $basis->value,
            'description' => $description,
            'currency_code' => $foreign,
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => $source,
        ]);
    }

    check($trip->status->value === 'planned', 'the probe reaches a planned trip with two ready charges in '.$foreign);

    /* ------------------------------------------------------------------ A: foreign issuing */
    echo "\n== A: a corridor trip is billed in its own currency ==\n";

    $sequenceBeforeFirst = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')->max('next_value');

    $invoice = (app(IssueInvoice::class))($trip->id, $issuer->id);
    $invoiceIds[] = $invoice->id;

    check($invoice->currency_code === $foreign, 'the document states the currency the quote was agreed in', $invoice->currency_code);
    check($invoice->base_currency_code === $base, '...and names the currency the books are kept in beside it', $invoice->base_currency_code);
    check((int) $invoice->amount === 1_250_000, '...net in the document currency: 1 250 000 minor, the sum of the charges', (string) $invoice->amount);
    check((int) $invoice->base_amount === 6_900_000, '...net in the books: 6 900 000 minor, the sum of the FROZEN bases, never a re-conversion', (string) $invoice->base_amount);
    check((string) $invoice->fx_rate === '5.52000000', '...the rate is the one those two figures imply: 5.52000000, blended and derived', (string) $invoice->fx_rate);
    check($invoice->fx_rate_source === FxRateSource::Contract->value, '...and says where it came from: the agreed quote, not a bulletin', (string) $invoice->fx_rate_source);
    // VAT comes off the rate the dev chart actually holds, not a number typed into this file: the
    // corridor's percentage is a Q4 configuration, and a harness that hardcodes 12% fails the
    // week somebody raises it.
    $expectedTax = (int) $taxRate?->taxOn(1_250_000);
    $expectedTaxBase = (int) $taxRate?->taxOnBase(6_900_000);
    $grossMinor = 1_250_000 + $expectedTax;
    $grossBaseMinor = 6_900_000 + $expectedTaxBase;

    check((int) $invoice->tax_amount === $expectedTax, '...VAT computed per line and rounded once at the rate the chart holds', (string) $invoice->tax_amount);
    check((int) $invoice->tax_base === $expectedTaxBase, '...with its own base figure', (string) $invoice->tax_base);
    check((int) $invoice->balance_amount === $grossMinor, '...the document balance is '.$grossMinor.' minor in '.$foreign, (string) $invoice->balance_amount);
    check((int) $invoice->balance_base === $grossBaseMinor, '...and '.$grossBaseMinor.' minor in '.$base, (string) $invoice->balance_base);

    $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('position')->get();

    check($lines->count() === 2 && $lines[0]->currency_code === $foreign, 'each line carries the document currency too', (string) $lines[0]->currency_code);
    check((int) $lines[0]->amount === 1_000_000 && (int) $lines[0]->base_amount === 5_500_000, '...and keeps its own frozen pair — the chargeable’s figures, untouched');
    check((int) $lines[1]->amount === 250_000 && (int) $lines[1]->base_amount === 1_400_000, '...including the line priced at a different rate');

    $entry = JournalEntry::query()->with('lines')->where('source_type', 'invoice')->where('source_id', $invoice->id)->first();
    $debit = $entry === null ? 0 : (int) $entry->lines->filter(fn ($line): bool => $line->direction === Side::Debit)->sum('amount_base');
    $credit = $entry === null ? 0 : (int) $entry->lines->filter(fn ($line): bool => $line->direction === Side::Credit)->sum('amount_base');

    check($entry!== null, 'the issue posted one journal entry');
    check($debit === $grossBaseMinor && $credit === $grossBaseMinor, '...the receivable is debited with balance_base and the entry balances', $debit.' vs '.$credit);

    Artisan::call('invoices:recompute-balances', ['--check' => true, '--company' => 1]);
    check(! str_contains(Artisan::output(), (string) $invoice->number), 'the projection tie-out does not report this document as drift');
    $sequenceAfterFirst = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')->max('next_value');

    /* ------------------------------------------------------------------ B: the refusals */
    echo "\n== B: what a foreign document may not be ==\n";

    $secondTrip = (app(CreateTrip::class))($job, $issuer->id);
    $secondTrip = (app(PlanTrip::class))($secondTrip, new TripDto(
        jobId: $job->id,
        truckId: $truck->id,
        driverId: $driver->id,
        trailerId: $trailer->id,
        plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
    ), $issuer->id);
    $tripIds[] = $secondTrip->id;

    foreach ([
        [ChargeableType::Freight, ChargeableBasis::PerTrip, 'FXV mixed leg: priced in the quote currency', $foreign, 500_000, 2_500_000, 'fxv:four'],
        // The same trip with a base-currency charge on it: one document states one currency, so the
        // run that reaches this pair must refuse rather than silently bill one and drop the other.
        [ChargeableType::Other, ChargeableBasis::Fixed, 'FXV mixed leg: priced in the base currency', $base, 100_000, 100_000, 'fxv:five'],
    ] as [$type, $basis, $description, $currency, $amount, $baseAmount, $source]) {
        Chargeable::create([
            'id' => (string) Str::ulid(),
            'trip_id' => $secondTrip->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => $type->value,
            'basis' => $basis->value,
            'description' => $description,
            'currency_code' => $currency,
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => $source,
        ]);
    }

    refuses('one document cannot mix currencies', fn () => (app(IssueInvoice::class))($secondTrip->id, $issuer->id), 'both');

    /* ------------------------------------------------------------------ C: manual draft */
    echo "\n== C: a hand-raised document is paper until it is issued ==\n";

    $draft = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $other->id,
        lines: [
            new ManualInvoiceLineDto(description: 'FXV sundry: container cleaning', amountMinor: 450_00),
            new ManualInvoiceLineDto(description: 'FXV sundry: toll recharge', amountMinor: 120_00, kind: InvoiceLineKind::Other),
        ],
        note: 'raised by hand for the harness',
    ), $issuer->id);
    $invoiceIds[] = $draft->id;

    check($draft->kind === InvoiceKind::Manual, 'the document is a manual one');
    check($draft->status === InvoiceLifecycle::Draft, '...and it is a draft');
    check($draft->number === null, '...with no fiscal number spent', var_export($draft->number, true));
    check((int) $draft->tax_amount === 0, '...no VAT computed yet — the rate is the one effective on the issue date (Q4)', (string) $draft->tax_amount);
    check((int) $draft->amount === 570_00 && (int) $draft->base_amount === 570_00, '...net 570 00 minor in both columns, because it is a base document', (string) $draft->amount);
    check((string) $draft->fx_rate === '1.00000000' || (float) $draft->fx_rate === 1.0, '...carrying the identity rate', (string) $draft->fx_rate);
    check(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $draft->id)->count() === 0, '...and nothing in the journal — a draft is not a receivable');

    refuses('a freight line cannot be raised by hand', fn () => (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $other->id,
        lines: [new ManualInvoiceLineDto(description: 'FXV impossible freight line', amountMinor: 100_00, kind: InvoiceLineKind::Freight)],
    ), $issuer->id), 'cannot be "Freight"');

    refuses('a line with no amount is not a document', fn () => (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $other->id,
        lines: [new ManualInvoiceLineDto(description: 'FXV nothing', amountMinor: 0)],
    ), $issuer->id), 'positive amount');

    /* ------------------------------------------------------------------ D: issue the draft */
    echo "\n== D: issuing spends the number and posts the receivable ==\n";

    $issued = (app(IssueManualInvoice::class))($draft, $issuer->id);

    check($issued->status === InvoiceLifecycle::Issued, 'the draft is issued');
    check($issued->number !== null && preg_match('/^INV-/', (string) $issued->number) === 1, '...and only now carries INV-{PERIOD}-{SEQ:7}', (string) $issued->number);
    check((int) $issued->tax_amount === $taxRate?->taxOn(570_00), '...VAT computed on the whole net at today\'s rate', (string) $issued->tax_amount);
    check((int) $issued->balance_amount === 570_00 + (int) $issued->tax_amount, '...so the balance is net plus VAT — the document asks for it', (string) $issued->balance_amount);

    $manualEntry = JournalEntry::query()->with('lines')->where('source_type', 'invoice')->where('source_id', $issued->id)->first();
    $accounts = DB::table('gl_accounts')->pluck('code', 'id');

    $byCode = [];

    foreach ($manualEntry?->lines ?? [] as $row) {
        $code = (string) ($accounts[$row->account_id] ?? '?');
        $byCode[$code.'.'.$row->direction->value] = (int) $row->amount_base;
    }

    check(($byCode['1150.debit'] ?? 0) === (int) $issued->balance_base, '...Dr 1150 for the base gross', json_encode($byCode));
    check(($byCode['4900.credit']?? 0) === 570_00, '...Cr 4900 Other income for the net — the account a manual line owns');
    check(($byCode['2125.credit'] ?? 0) === (int) $issued->tax_base, '...Cr 2125 Output VAT for the tax');

    refuses('an issued document cannot be issued twice', fn () => (app(IssueManualInvoice::class))($issued, $issuer->id), 'only a draft');
    refuses('…nor discarded once it is a fiscal document', fn () => (app(DiscardManualInvoice::class))($issued, 'verify: should be refused', $issuer->id), 'never');
    $invoiceIds[] = $issued->id;

    /* ------------------------------------------------------------------ E: discard a draft */
    echo "\n== E: a draft nobody issued is discarded, and the books never knew it ==\n";

    $doomed = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $other->id,
        lines: [new ManualInvoiceLineDto(description: 'FXV draft to be discarded', amountMinor: 99_00)],
    ), $issuer->id);
    $invoiceIds[] = $doomed->id;

    (app(DiscardManualInvoice::class))($doomed, 'verify: the harness raised it to prove the delete', $issuer->id);

    check(Invoice::query()->whereKey($doomed->id)->count() === 0, 'the draft row is gone');
    check(InvoiceLine::query()->where('invoice_id', $doomed->id)->count() === 0, '...its lines with it (the FK cascades; no chargeable was ever claimed)');
    check(DB::table('operational_events')->where('aggregate_type', 'invoice')->where('aggregate_id', $doomed->id)->where('to_state', 'discarded')->exists(), '...but the trail kept the reason');

    refuses('a discard needs a sentence', fn () => (app(DiscardManualInvoice::class))($issued, 'short', $issuer->id), 'ten characters');

    /* ------------------------------------------------------------------ F: foreign manual */
    echo "\n== F: a hand-raised document in a corridor currency (ADR-0004 #6) ==\n";

    $fxDraft = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $customer->id,
        lines: [
            new ManualInvoiceLineDto(description: 'FXV foreign line one', amountMinor: 500_00, kind: InvoiceLineKind::Manual),
            new ManualInvoiceLineDto(description: 'FXV foreign line two', amountMinor: 500_00, kind: InvoiceLineKind::Manual),
        ],
        currencyCode: $foreign,
        fxRate: '5.5',
    ), $issuer->id);
    $invoiceIds[] = $fxDraft->id;

    $fxLines = InvoiceLine::query()->where('invoice_id', $fxDraft->id)->orderBy('position')->get();

    check((int) $fxDraft->amount === 100_000 && (int) $fxDraft->base_amount === 550_000, '...a typed rate converts the document: 1 000.00 '.$foreign.' at 5.5 is 5 500.00 '.$base, (string) $fxDraft->base_amount);
    check((int) $fxLines[0]->base_amount === 275_000 && (int) $fxLines[1]->base_amount === 275_000, '...each line converted and rounded on its own', (int) $fxLines[0]->base_amount.' / '.(int) $fxLines[1]->base_amount);
    check($fxDraft->fx_rate_source === FxRateSource::Manual->value, '...marked manual, because the desk typed the figure', (string) $fxDraft->fx_rate_source);

    $overrideDraft = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $customer->id,
        lines: [
            new ManualInvoiceLineDto(description: 'FXV residue line one', amountMinor: 500_00),
            new ManualInvoiceLineDto(description: 'FXV residue line two', amountMinor: 500_00),
        ],
        currencyCode: $foreign,
        baseAmountMinor: 550_001,
    ), $issuer->id);
    $invoiceIds[] = $overrideDraft->id;

    $overrideLines = InvoiceLine::query()->where('invoice_id', $overrideDraft->id)->orderBy('position')->get();

    check(array_sum($overrideLines->pluck('base_amount')->all()) === 550_001, '...a typed BASE total is honoured exactly: the lines sum to 550 001 minor', (string) array_sum($overrideLines->pluck('base_amount')->all()));
    check((int) $overrideLines[0]->base_amount === 275_001, '...the first line converts and rounds straight at the implied 5.50001', (string) $overrideLines[0]->base_amount);
    check((int) $overrideLines[1]->base_amount === 275_000, '...and the LAST line absorbs the ±1 residue (moved one layer up)', (string) $overrideLines[1]->base_amount);
    check($overrideDraft->fx_rate_source === FxRateSource::ManualOverride->value, '...with the source saying the base figure, not a rate, was typed', (string) $overrideDraft->fx_rate_source);

    // The desk typed neither figure, so the document either takes the stored bulletin rate and says
    // where it came from, or is refused for want of one. Both are correct; guessing silently is not.
    $bulletin = app(RateResolver::class)->resolve($foreign, Illuminate\Support\Carbon::now());

    if ($bulletin === null) {
        refuses('a foreign document with neither rate nor base is refused when the register is empty', fn () => (app(CreateManualInvoice::class))(new ManualInvoiceDto(
            customerId: $customer->id,
            lines: [new ManualInvoiceLineDto(description: 'FXV unconverted', amountMinor: 100_00)],
            currencyCode: $foreign,
            fxRateSource: FxRateSource::Manual->value,
        ), $issuer->id), $foreign);
    } else {
        $autoDraft = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
            customerId: $customer->id,
            lines: [new ManualInvoiceLineDto(description: 'FXV bulletin-priced', amountMinor: 100_00)],
            currencyCode: $foreign,
        ), $issuer->id);
        $invoiceIds[] = $autoDraft->id;

        check((float) $autoDraft->fx_rate === (float) $bulletin->rate()
            && $autoDraft->fx_rate_source === $bulletin->source->value,
            '...a document raised with no rate takes the stored bulletin rate and names its source ('.(string) $autoDraft->fx_rate_source.' @ '.(string) $autoDraft->fx_rate.')',
            (string) $autoDraft->fx_rate.' / '.(string) $autoDraft->fx_rate_source.' vs '.$bulletin->rate().' / '.$bulletin->source->value);
    }

    /* ------------------------------------------------------------------ G: deposits */
    echo "\n== G: money that arrives with no invoice named (phase 4's deposits) ==\n";

    $deposit = (app(RecordPayment::class))(new PaymentDto(
        customerId: $other->id,
        amountMinor: 200_00,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'FXV base deposit',
    ), $issuer->id);
    $paymentIds[] = $deposit->id;

    check($deposit->currency_code === $base, 'a base receipt is the identity block, as left it', $deposit->currency_code);
    check((int) $deposit->amount === 200_00 && (int) $deposit->base_amount === 200_00, '...amount and base both 200 00 minor');
    check((int) $deposit->unapplied_amount === 200_00 && (int) $deposit->unapplied_base === 200_00, '...all of it unapplied — nothing claimed it', (string) $deposit->unapplied_amount);

    $bankEntry = JournalEntry::query()->with('lines')->where('source_type', 'payment')->where('source_id', $deposit->id)->first();
    $bankCodes = [];

    foreach ($bankEntry?->lines ?? [] as $row) {
        $bankCodes[(string) ($accounts[$row->account_id] ?? '?')] = $row->direction->value.' '.(int) $row->amount_base;
    }

    check(in_array('debit 20000', $bankCodes, true), '...banked Dr at the figure it arrived as', json_encode($bankCodes));
    check(($bankCodes['2150']?? '') === 'credit 20000', '...and Cr 2150 Unapplied customer cash', $bankCodes['2150']?? '-');

    /*
     * The seam draws, tested while the customer holds only Pula: a corridor document cannot be
     * paid with base money. The register keeps each receipt in its own currency until a document of
     * that currency spends it — converting on the fly is the bank's job, not the ledger's.
     */
    $allocate = app(AllocatePayment::class);

    $probeDraft = (app(CreateManualInvoice::class))(new ManualInvoiceDto(
        customerId: $customer->id,
        lines: [new ManualInvoiceLineDto(description: 'FXV probe: a ZAR document nothing may pay', amountMinor: 50_000)],
        currencyCode: $foreign,
        fxRate: '5.5',
    ), $issuer->id);
    $invoiceIds[] = $probeDraft->id;

    $probeInvoice = (app(IssueManualInvoice::class))($probeDraft, $issuer->id);
    $probePayment = (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: 300_000,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'FXV pula only',
    ), $issuer->id);
    $paymentIds[] = $probePayment->id;

    refuses('base money cannot settle a foreign invoice', fn () => $allocate($probeInvoice, $issuer->id), 'one '.$foreign.' receipt');

    // The receipt that closes the corridor invoice is for its gross in the document currency, bought
    // at 5.6 — a rate the invoice never saw. Expected cash is computed here with integer arithmetic
    // (HALF_UP at one decimal), so the figure is proven independently of the path under test.
    $gainCash = intdiv($grossMinor * 56 + 5, 10);
    $gainDiff = $gainCash - $grossBaseMinor;

    $fxDeposit = (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: $grossMinor,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'FXV foreign receipt at 5.6',
        currencyCode: $foreign,
        fxRate: '5.6',
        fxRateSource: FxRateSource::BankStatement->value,
    ), $issuer->id);
    $paymentIds[] = $fxDeposit->id;

    check($fxDeposit->currency_code === $foreign, 'a corridor receipt names its own currency', $fxDeposit->currency_code);
    check((int) $fxDeposit->base_amount === $gainCash, '...and freezes the base at the rate the bank struck: '.$gainCash.' minor', (string) $fxDeposit->base_amount);
    check((int) $fxDeposit->unapplied_amount === $grossMinor, '...the pile it holds is in '.$foreign.', not Pula — a ZAR receipt is not Pula', (string) $fxDeposit->unapplied_amount);
    check((int) $fxDeposit->unapplied_base === $gainCash, '...with its base half alongside', (string) $fxDeposit->unapplied_base);

    $fxBank = JournalEntry::query()->with('lines')->where('source_type', 'payment')->where('source_id', $fxDeposit->id)->first();
    $fxBankLines = [];

    foreach ($fxBank?->lines ?? [] as $row) {
        $fxBankLines[(string) ($accounts[$row->account_id] ?? '?')] = (int) $row->amount_base;
    }

    check(($fxBankLines['1120']?? 0) === $gainCash && ($fxBankLines['2150']?? 0) === $gainCash, '...and the LEDGER sees only the base it actually holds', json_encode($fxBankLines));

    refuses('a foreign receipt without a rate or a base is refused', fn () => (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: 100_000,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'FXV unconverted receipt',
        currencyCode: $foreign,
    ), $issuer->id), 'rate');

    /* ------------------------------------------------------------------ H: realised FX */
    echo "\n== H: realised FX where the receipt’s rate meets the invoice’s own ==\n";

    $gain = $allocate($invoice, $issuer->id);
    $gainAllocation = PaymentAllocation::query()->where('invoice_id', $gain->id)->latest('created_at')->first();

    check((int) $gain->balance_amount === 0 && (int) $gain->balance_base === 0, 'the foreign invoice is settled to exactly zero in BOTH columns', $gain->balance_amount.'/'.$gain->balance_base);
    check($gainAllocation !== null && (int) $gainAllocation->amount === $grossMinor, '...one allocation of the whole '.$foreign.' balance closed it', (string) ($gainAllocation?->amount ?? -1));
    check((int) ($gainAllocation?->base_amount ?? 0) === $grossBaseMinor, '...releasing the receivable at the base the invoice was booked at', (string) ($gainAllocation?->base_amount ?? -1));
    check((bool) ($gainAllocation?->has_fx_difference?? false) && $gainAllocation?->fx_difference_side === 'gain' && (int) $gainAllocation?->fx_difference_base === $gainDiff, '...naming the '.$gainDiff.' minor difference as a gain', ($gainAllocation?->fx_difference_side?? '-').' '.(int) ($gainAllocation?->fx_difference_base?? -1));

    $gainEntry = JournalEntry::query()->with('lines')->where('source_type', 'payment_allocation')->where('source_id', (string) $gainAllocation?->id)->first();
    $gainCodes = [];

    foreach ($gainEntry?->lines ?? [] as $row) {
        $gainCodes[(string) ($accounts[$row->account_id] ?? '?')] = $row->direction->value.' '.(int) $row->amount_base;
    }

    check(($gainCodes['2150'] ?? '') === 'debit '.$gainCash, '...Dr 2150 for the cash the receipt really is', $gainCodes['2150'] ?? '-');
    check(($gainCodes['1150'] ?? '') === 'credit '.$grossBaseMinor, '...Cr 1150 for the receivable as the invoice booked it', $gainCodes['1150'] ?? '-');
    check(($gainCodes['7510']?? '') === 'credit '.$gainDiff, '...and Cr 7510 Realised FX gain for the difference — named, not buried', $gainCodes['7510']?? '-');

    $spentReceipt = Payment::query()->find($fxDeposit->id);

    check((int) $spentReceipt?->unapplied_amount === 0 && (int) $spentReceipt?->unapplied_base === 0, '...the receipt closed in both halves together, residue and all', (int) ($spentReceipt?->unapplied_amount?? -1).'/'.(int) ($spentReceipt?->unapplied_base?? -1));
    check(Payment::query()->where('reference', 'FXV pula only')->where('unapplied_base', 300_000)->exists(), '...while the Pula receipt is still on the account, unapplied and unchanged');

    // A loss the other way: the same document reborn as a foreign manual invoice, paid at a weaker rate.
    $lossInvoice = (app(IssueManualInvoice::class))($fxDraft, $issuer->id);
    $lossGrossMinor = (int) $lossInvoice->balance_amount;
    $lossGrossBase = (int) $lossInvoice->balance_base;
    $lossCash = intdiv($lossGrossMinor * 54 + 5, 10);
    $lossDiff = $lossGrossBase - $lossCash;

    $lossPayment = (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: $lossGrossMinor,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'FXV receipt at 5.4',
        currencyCode: $foreign,
        fxRate: '5.4',
        fxRateSource: FxRateSource::BankStatement->value,
    ), $issuer->id);
    $paymentIds[] = $lossPayment->id;

    check((int) $lossPayment->base_amount === $lossCash, 'the weaker rate bought '.$lossCash.' minor of '.$base.' for the same paper', (string) $lossPayment->base_amount);

    $settledLoss = $allocate($lossInvoice, $issuer->id);
    $lossAllocation = PaymentAllocation::query()->where('invoice_id', $settledLoss->id)->latest('created_at')->first();
    $lossEntry = JournalEntry::query()->with('lines')->where('source_type', 'payment_allocation')->where('source_id', (string) $lossAllocation?->id)->first();

    $lossCodes = [];

    foreach ($lossEntry?->lines ?? [] as $row) {
        $lossCodes[(string) ($accounts[$row->account_id] ?? '?')] = $row->direction->value.' '.(int) $row->amount_base;
    }

    check((int) $settledLoss->balance_amount === 0, 'a foreign manual document settles the same way as a trip one', (string) $settledLoss->balance_amount);
    check($lossAllocation?->fx_difference_side === 'loss', '...and this time the receipt’s rate was weaker: the difference is a loss', $lossAllocation?->fx_difference_side ?? '-');
    check((int) ($lossAllocation?->fx_difference_base?? 0) === $lossDiff, '...cash '.$lossCash.' minor against '.$lossGrossBase.' minor booked is '.$lossDiff.' minor of loss', (string) ($lossAllocation?->fx_difference_base?? -1));
    check(($lossCodes['7511'] ?? '') === 'debit '.$lossDiff, '...booked as Dr 7511 Realised FX loss, mirroring the gain’s credit', json_encode($lossCodes));

    $tieOutExit = Artisan::call('invoices:recompute-balances', ['--check' => true, '--company' => 1]);
    $tieOut = Artisan::output();

    $myNumbers = array_filter(array_merge(
        $invoiceIds === [] ? [] : DB::table('invoices')->whereIn('id', $invoiceIds)->pluck('number')->all(),
        $paymentIds === [] ? [] : DB::table('payments')->whereIn('id', array_filter($paymentIds))->pluck('number')->all(),
    ), static fn ($number): bool => $number !== null && $number !== '');

    $named = 0;

    foreach ($myNumbers as $number) {
        if (str_contains($tieOut, (string) $number)) {
            $named++;
        }
    }

    check($tieOutExit === 0 || $named === 0, 'the tie-out rebuilds both projections from these rows with no drift of mine', trim($tieOut));

    /* ------------------------------------------------------------------ I: one register, one order */
    echo "\n== I: the fiscal register counted exactly the documents it issued ==\n";

    $sequenceAfterLast = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')->max('next_value');

    check($sequenceAfterLast - $sequenceAfterFirst === 3, '...three more numbers spent — the base manual issue, the probe that nothing could pay, and the foreign one', ($sequenceAfterLast - $sequenceAfterFirst).' consumed');
    check($sequenceAfterFirst - $sequenceBeforeFirst === 1, '...and exactly one for the corridor trip invoice, whose refusal in section B consumed none', ($sequenceAfterFirst - $sequenceBeforeFirst).' consumed');
    check(Invoice::query()->whereNotNull('number')->where('company_id', 1)->where('kind', InvoiceKind::Manual->value)->whereNull('job_id')->count() >= 2, '...the manual documents that reached the register are the issued ones');

    ok('the run left its rows for cleanup: '.count($invoiceIds).' invoices, '.count($paymentIds).' receipts');
} catch (Throwable $blew) {
    // A harness that dies mid-run is not a clean refusal — say where, then still remove what it wrote.
    fail('the run stopped: '.get_class($blew), $blew->getMessage().' @ line '.$blew->getLine());
} finally {
    echo "\nCleanup\n-------\n";
    // The same sweep the run started with, now handed this run's ids as well — a row the FXV
    // markers cannot see is still taken (the proof must be re-runnable, not lucky).
    $sweep($invoiceIds, $paymentIds);

    $left = DB::table('chargeables')->where('description', 'like', 'FXV %')->count()
        + DB::table('customers')->where('code', 'like', 'FXV-%')->count()
        + DB::table('trips')->where('id', $tripIds[0] ?? '0')->count();

    $left === 0 ? ok('no FXV rows left behind') : fail('leftover FXV rows', (string) $left);
}

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — PHASE 4 COMPLETION OK' : ' — PHASE 4 COMPLETION FAILED')."\n";

exit($failures === 0 ? 0 : 1);
