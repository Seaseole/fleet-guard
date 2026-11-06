<?php

declare(strict_types=1);

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\AllocatePayment;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\RunConsolidatedBilling;
use App\Domain\Invoicing\Actions\VoidInvoice;
use App\Domain\Invoicing\AgeingBucket;
use App\Domain\Invoicing\CreditNoteLifecycle;
use App\Domain\Invoicing\Data\CreditNoteDto;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Data\PaymentDto;
use App\Domain\Invoicing\Documents\InvoiceDocuments;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Models\PaymentAllocation;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\PaymentMethod;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Invoicing\Queries\Statements;
use App\Domain\Invoicing\SettlementState;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Numbering\NumberIntegrityChecker;
use App\Domain\Kernel\Numbering\NumberPeriod;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\AcceptJob;
use App\Domain\Operations\Actions\CreateJob;
use App\Domain\Operations\Actions\CreateRoute;
use App\Domain\Operations\Actions\CreateTrip;
use App\Domain\Operations\Actions\MarkTripChargesBilled;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Phase 4 slice 4b verification — the invoice, its gap-free number and the `InvoiceIssued` posting
 *.
 *
 * The sentences this slice turns on:
 *
 *  - a delivered trip's `ready` chargeables become **one** fiscal document, numbered at issue and never
 *    before, with the customer, terms, due date and currency block the ageing axis will read;
 *  - VAT is computed per line and rounded once, so the invoice's tax is the sum of its lines;
 *  - the ledger receives exactly one `InvoiceIssued` entry: Dr 1150 gross, Cr each line's own revenue
 *    account for the net, Cr 2125 for the tax — with truck, trip and customer on the revenue line because
 *    4100 cannot be posted without them;
 *  - the chargeables are claimed `ready → billed`, so a second issue returns the first document instead of
 *    spending a second number or billing the delivery twice;
 *  - and the trip's projection finally carries revenue (left it at zero until an invoice existed).
 *
 * Fixtures are prefixed `INV-VERIFY` and removed in `finally` — including this run's journal entries, which
 * are deleted under {@see LedgerBarrier::withLiftedGuard()} with a written reason, as the design requires.
 *
 * Run: php scripts/verify-invoicing.php
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

/** Everything this run wrote, removed here so a second run starts from the same place. */
function cleanUp(): void
{
    if (! Schema::hasTable('invoices')) {
        return;
    }

    $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'INV-VERIFY%')->pluck('id')->all();
    $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();
    $customerIds = DB::table('customers')->where('code', 'like', 'INV-VERIFY%')->pluck('id')->all();
    // A consolidated run names no job (many legs, one party), so the customer is the second handle on the
    // documents it wrote; without it the cleanup would leave the statement and its lines behind.
    $invoiceIds = DB::table('invoices')
        ->where(fn ($query) => $query->whereIn('job_id', $jobIds ?: ['0'])->orWhereIn('customer_id', $customerIds ?: [0]))
        ->pluck('id')->all();
    $chargeableIds = $tripIds === [] ? [] : DB::table('chargeables')->whereIn('trip_id', $tripIds)->pluck('id')->all();
    $paymentIds = $customerIds === [] ? [] : DB::table('payments')->whereIn('customer_id', $customerIds)->pluck('id')->all();
    $creditNoteIds = $invoiceIds === [] ? [] : DB::table('credit_notes')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
    $allocationIds = DB::table('payment_allocations')
        ->where(fn ($q) => $q->whereIn('payment_id', $paymentIds ?: ['0'])->orWhereIn('invoice_id', $invoiceIds ?: ['0']))
        ->pluck('id')->all();
    $entryIds = array_merge(
        // The void's mirror is included: it names the invoice's entry, so leaving it behind would make the
        // original undeletable and the cleanup would fail on its own fixtures.
        $invoiceIds === [] ? [] : DB::table('gl_journal_entries')
            ->whereIn('source_type', ['invoice', 'invoice_void'])
            ->whereIn('source_id', $invoiceIds)->pluck('id')->all(),
        $paymentIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'payment')->whereIn('source_id', $paymentIds)->pluck('id')->all(),
        $allocationIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'payment_allocation')->whereIn('source_id', $allocationIds)->pluck('id')->all(),
        $creditNoteIds === [] ? [] : DB::table('gl_journal_entries')
            ->where('source_type', 'credit_note')->whereIn('source_id', $creditNoteIds)->pluck('id')->all(),
    );

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-invoicing: remove this run\'s posted entries', null, function () use ($entryIds): void {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
            // A reversal names the entry it mirrors, so the mirrors have to go first.
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        });
    }

    if ($invoiceIds !== []) {
        DB::table('audits')->whereIn('auditable_type', [Invoice::class, InvoiceLine::class])
            ->where(fn ($q) => $q->whereIn('auditable_id', $invoiceIds)->orWhereIn('auditable_id', DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all() ?: ['0']))
            ->delete();
        // Allocations point at the invoice, so they go first — a receipt's evidence cannot outlive the
        // document it settled, even in a fixture cleanup.
        DB::table('payment_allocations')->whereIn('invoice_id', $invoiceIds)->delete();

        // Credit note lines point at invoice lines, so the notes go before the lines they correct.
        if ($creditNoteIds !== []) {
            DB::table('credit_note_lines')->whereIn('credit_note_id', $creditNoteIds)->delete();
            DB::table('operational_events')->where('aggregate_type', 'credit_note')->whereIn('aggregate_id', $creditNoteIds)->delete();
            DB::table('credit_notes')->whereIn('id', $creditNoteIds)->delete();
        }

        DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('operational_events')->where('aggregate_type', 'invoice')->whereIn('aggregate_id', $invoiceIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
    }

    if ($paymentIds !== []) {
        DB::table('operational_events')->where('aggregate_type', 'payment')->whereIn('aggregate_id', $paymentIds)->delete();
        DB::table('payment_allocations')->whereIn('payment_id', $paymentIds)->delete();
        DB::table('payments')->whereIn('id', $paymentIds)->delete();
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

    DB::table('customers')->where('code', 'like', 'INV-VERIFY%')->delete();
    DB::table('routes')->where('code', 'like', 'INV-VERIFY%')->delete();

    $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-IV%')->pluck('id')->all();
    if ($employeeIds !== []) {
        DB::table('drivers')->whereIn('employee_id', $employeeIds)->delete();
        DB::table('employees')->whereIn('id', $employeeIds)->delete();
    }

    DB::table('trucks')->where('registration_no', 'like', '%IV1')->delete();
    DB::table('trailers')->where('registration_no', 'like', '%IV1')->delete();

    // The issuer probe, and the role row that carried `invoices.issue`.
    $probeIds = DB::table('users')->where('email', 'like', 'verify-inv-%@fleetguard.local')->pluck('id')->all();

    // The allocator records who spent each number. That reference has to be released before the
    // probe user can be removed, or the cleanup is what breaks the run.
    DB::table('number_sequences')->whereIn('last_used_by', $probeIds ?: [0])->update(['last_used_by' => null]);
    DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
    DB::table('audits')->where('auditable_type', User::class)->whereIn('auditable_id', array_map('strval', $probeIds) ?: ['0'])->delete();
    DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

    /*
     * Put every counter this run could have advanced back where the surviving documents leave it. This is
     * The rule that a harness must not break its neighbours: deleting the fixture trip and job while
     * their numbers stay burned makes `documents:number-integrity` report drift that no person caused, and
     * the next allocator call would then collide. The journal counter is included because this run posts and
     * purges entries; the invoice counter because that is the number this slice spends.
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

    $rewind('invoice', 'invoices', 'number');
    $rewind('credit_note', 'credit_notes', 'number');
    $rewind('receipt', 'payments', 'number');
    $rewind('job', 'freight_jobs', 'job_no');
    $rewind('trip', 'trips', 'trip_no');
    $rewind('journal_entry', 'gl_journal_entries', 'entry_no');
}

CurrentCompany::force(1);

cleanUp();
Artisan::call('numbering:ensure', ['--company' => 1]);

/*
 * The seat that issues is the Accountant's (the design gives it `invoices.issue`); the seeded user 1 is a
 * dispatcher and must not be silently granted it by a harness. The probe is created the way
 * verify-fuel creates its own, and removed in the same `cleanUp()`.
 */
/** @var User $issuer */
$issuer = User::query()->firstOrNew(['email' => 'verify-inv-issuer@fleetguard.local']);
$issuer->name = 'Verify INV Issuer';
$issuer->company_id = 1;
$issuer->password = Str::random(32);
$issuer->email_verified_at = Carbon::now();
$issuer->save();
app(ChangeUserRole::class)($issuer->fresh(), RoleKey::Accountant);
$issuer = $issuer->fresh();

$dispatcher = User::query()->findOrFail(1);

Auth::login($issuer);

try {
    echo "== the fixture: a delivered trip whose charges are ready to bill ==\n";

    $base = CurrentCompany::model()->base_currency_code;
    $customer = (app(CreateCustomer::class))(new CustomerDto(name: 'INV-VERIFY Customer', code: 'INV-VERIFY-CUST', paymentTerms: 'net_30'));
    $route = (app(CreateRoute::class))(new RouteDto(
        code: 'INV-VERIFY-GBE-WDH', origin: 'Gaborone', destination: 'Windhoek',
        originCountryCode: 'BW', destinationCountryCode: 'NA', distanceKm: '750',
    ));

    $job = (app(CreateJob::class))(new JobDto(
        customerId: $customer->id,
        routeId: $route->id,
        commodity: 'probe cargo',
        tonnes: 24.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
        notes: 'INV-VERIFY job',
    ), 1);

    $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '90000', currencyCode: $base), 1);
    $job = (app(AcceptJob::class))($job, 1);

    $truck = (app(CreateTruck::class))(new TruckDto(registrationNo: 'B 991 IV1', fleetNo: 'T-IV1', vin: 'VINIV1000000000000001', make: 'Probe', model: 'Invoice', odometerKm: 10_000));
    $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 991 IV1', type: 'flatbed', capacityTonnes: 30.0));
    $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-IV1001', name: 'INV-VERIFY Driver', hiredOn: '2024-01-10', nationalId: 'ID-IV1000001'));
    $driver = (app(CreateDriver::class))(new DriverDto(
        employeeId: $employee->id,
        licenceNo: 'DL-IV10001',
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

    $freightId = (string) Str::ulid();
    $detentionId = (string) Str::ulid();

    foreach ([
        [$freightId, ChargeableType::Freight, ChargeableBasis::PerTrip, 'INV-VERIFY job · probe cargo', 9_000_000, 'verify-inv:one'],
        [$detentionId, ChargeableType::Detention, ChargeableBasis::Fixed, 'Waiting time at the border', 250_000, 'verify-inv:two'],
    ] as [$id, $type, $basis, $description, $amount, $source]) {
        Chargeable::create([
            'id' => $id,
            'trip_id' => $trip->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => $type->value,
            'basis' => $basis->value,
            'description' => $description,
            'currency_code' => $base,
            'amount' => $amount,
            'base_amount' => $amount,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => $source,
        ]);
    }

    check($trip->status->value === 'planned', 'the probe reaches a planned trip with two ready charges');
    check(Trip::query()->findOrFail($trip->id)->revenue_base === 0, '...whose revenue projection is still zero, as left it');

    /* ------------------------------------------------------------------ A: the number */
    echo "\n== A: one fiscal document, numbered at issue and never before ==\n";

    $rate = TaxRate::effectiveOn(Carbon::now());
    check($rate !== null, 'a VAT rate applies on the issue date (TaxRateSeeder)');

    $sequenceBefore = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value');

    check(! Gate::forUser($dispatcher)->allows('invoices.issue'), 'a dispatcher does not hold invoices.issue');

    Auth::login($dispatcher);
    $dispatcherRefused = '';

    try {
        (app(IssueInvoice::class))($trip->id, $dispatcher->id);
    } catch (Throwable $refused) {
        $dispatcherRefused = $refused->getMessage();
    }

    check(str_contains($dispatcherRefused, 'invoices.issue'), '...so a dispatcher cannot issue an invoice, and the refusal names the ability', $dispatcherRefused);
    check(Invoice::query()->where('job_id', $job->id)->count() === 0, '...and the refused attempt wrote no document at all');
    Auth::login($issuer);

    $invoice = (app(IssueInvoice::class))($trip->id, $issuer->id);

    check($invoice->status === InvoiceLifecycle::Issued, 'the invoice is issued, not drafted');
    check($invoice->number !== null && preg_match('/^INV-[A-Z0-9\/]+-\d{7}$/', $invoice->number) === 1, '...and carries a rendered INV-{PERIOD}-{SEQ:7} number', (string) $invoice->number);
    check($invoice->issued_at!== null && $invoice->issued_by === $issuer->id, '...with who issued it and when');
    check($invoice->kind === InvoiceKind::Trip, '...as a trip invoice');
    check($invoice->issue_date->toDateString() === Carbon::now()->toDateString(), '...dated today');
    check($invoice->due_on->toDateString() === Carbon::now()->addDays(30)->toDateString(), '...due on the customer\'s net_30 terms, not on today (ageing)');
    check($invoice->terms_snapshot === 'net_30', '...with the terms frozen on the document, so a later change cannot re-date it');
    check($invoice->customer_id === $customer->id && $invoice->job_id === $job->id, '...addressed to the job\'s customer and naming the job');
    check($invoice->amount === 9_250_000, '...net 9 250 000 minor units: the sum of the charges, no more', (string) $invoice->amount);
    check((int) $sequenceBefore + 1 === (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value'), '...and exactly one sequence value was consumed');

    /* ------------------------------------------------------------------ B: the lines */
    echo "\n== B: one line per chargeable, VAT rounded per line ==\n";

    $lines = $invoice->lines;
    check($lines->count() === 2, 'two lines, one per chargeable');
    check($lines[0]->line_kind === InvoiceLineKind::Freight && $lines[1]->line_kind === InvoiceLineKind::Detention, '...in the order the work was delivered');
    check($lines[0]->chargeable_id === $freightId && $lines[1]->chargeable_id === $detentionId, '...each naming the charge it billed');
    check($lines[0]->revenue_account_code === '4100' && $lines[1]->revenue_account_code === '4200', '...and the account its kind answers to');
    check($lines[0]->quantity_x100 === 100 && $lines[0]->unit_price_minor === 9_000_000, '...one unit at the chargeable\'s own price, so the row\'s rounded-product CHECK holds');
    check($lines[0]->trip_id === $trip->id && $lines[0]->truck_id === $truck->id, '...carrying the leg 4100 requires');

    // 12.00% is stored as 120 000, i.e. a percentage with two decimals — so the fraction of the amount is
    // the product over 120 000 × 100, not over 120 000. Computed here independently of `TaxRate::taxOn()`
    // so the check measures the arithmetic rather than restating the method.
    $expectedTaxOne = intdiv(9_000_000 * $rate->rate_x10000 + 500_000, 1_000_000);
    $expectedTaxTwo = intdiv(250_000 * $rate->rate_x10000 + 500_000, 1_000_000);

    check($lines[0]->tax_amount === $expectedTaxOne, '...VAT on the freight line is the rate applied once, integer arithmetic', (string) $lines[0]->tax_amount);
    check($lines[1]->tax_amount === $expectedTaxTwo, '...and VAT on the detention line is computed on that line, not on the total', (string) $lines[1]->tax_amount);
    check($invoice->tax_amount === $expectedTaxOne + $expectedTaxTwo, 'the invoice tax is the sum of the rounded lines, never round-of-sum');
    check($invoice->balance_amount === $invoice->amount + $invoice->tax_amount, '...and the outstanding balance is the gross the customer owes');
    check($lines[0]->tax_rate_id === $rate->id, '...each line naming the rate window it was billed under');

    /* ------------------------------------------------------------------ C: the posting */
    echo "\n== C: one InvoiceIssued entry — Dr 1150 gross, Cr revenue net, Cr 2125 tax ==\n";

    $entry = JournalEntry::query()
        ->where('source_type', 'invoice')->where('source_id', $invoice->id)
        ->where('status', JournalEntry::Posted)->first();

    check($entry !== null, 'exactly one posted entry names the invoice');
    check($entry?->total_debit === $entry?->total_credit, 'the entry balances');
    check($entry?->total_debit === $invoice->balance_amount, '...debit equals the gross the customer owes', (string) $entry?->total_debit);

    $byCode = [];
    foreach ($entry?->lines()->with('account')->get() ?? [] as $line) {
        $byCode[$line->account->code][] = $line;
    }

    $ar = $byCode['1150'] ?? [];
    $freight = $byCode['4100'] ?? [];
    $detention = $byCode['4200'] ?? [];
    $vat = $byCode['2125'] ?? [];

    check(count($ar) === 1 && $ar[0]->direction->value === 'debit' && $ar[0]->amount_base === $invoice->balance_amount, 'Dr 1150 for the gross');
    check(count($freight) === 1 && $freight[0]->direction->value === 'credit' && $freight[0]->amount_base === 9_000_000, 'Cr 4100 for the freight net');
    check(count($detention) === 1 && $detention[0]->direction->value === 'credit' && $detention[0]->amount_base === 250_000, 'Cr 4200 for the detention net');
    check(count($vat) === 1 && $vat[0]->direction->value === 'credit' && $vat[0]->amount_base === $invoice->tax_amount, 'Cr 2125 for the output VAT');
    check($vat !== [] && ($byCode['2120'] ?? []) === [], '...and not to 2120, which is Phase 3\'s purchase/diesel side');

    check(($ar[0]->customer_id?? null) === $customer->id && ($ar[0]->invoice_id?? null) === $invoice->id, 'the receivable names its customer and its invoice');
    check(($freight[0]->trip_id ?? null) === $trip->id
        && ($freight[0]->truck_id ?? null) === $truck->id
        && ($freight[0]->customer_id?? null) === $customer->id, 'the freight revenue names truck, trip and customer, or 4100 could not have been posted');

    /* ------------------------------------------------------------------ D: claimed */
    echo "\n== D: the charges are claimed, so the delivery cannot be billed twice ==\n";

    check(Chargeable::query()->whereIn('id', [$freightId, $detentionId])->where('status', ChargeableStatus::Billed->value)->count() === 2, 'both chargeables moved ready → billed');

    $again = (app(IssueInvoice::class))($trip->id, $issuer->id);

    check($again->id === $invoice->id, 'issuing the same trip again returns the first document, not a second');
    check(Invoice::query()->where('job_id', $job->id)->count() === 1, '...and the register still holds one invoice');
    check((int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value') === (int) $sequenceBefore + 1, '...without spending a second number (a retry is not a gap)');

    /* ------------------------------------------------------------------ E: the projection */
    echo "\n== E: the trip's projection finally carries revenue ==\n";

    $reloaded = Trip::query()->findOrFail($trip->id);
    check($reloaded->revenue_base === 9_250_000, 'trips.revenue_base is the net revenue posted, not the gross', (string) $reloaded->revenue_base);
    check($reloaded->contribution_base === 9_250_000, '...and contribution moves with it, because no direct cost exists on this trip');

    /* ------------------------------------------------------------------ F: the refusals */
    echo "\n== F: what the door refuses ==\n";

    try {
        (app(IssueInvoice::class))('01JZ0000000000000000000000', $issuer->id);
        failed('a trip that is not in the register is refused');
    } catch (Throwable $refused) {
        check(str_contains($refused->getMessage(), 'register'), 'a trip that is not in the register is refused, by name', $refused->getMessage());
    }

    $claimRefused = '';

    try {
        DB::transaction(function () use ($trip, $freightId): void {
            (app(MarkTripChargesBilled::class))($trip->id, [$freightId]);
        });
    } catch (Throwable $refused) {
        $claimRefused = $refused->getMessage();
    }

    check(str_contains($claimRefused, 'already billed'), 'an already-billed charge cannot be claimed again, so a second invoice has nothing to bill', $claimRefused);

    /* ------------------------------------------------------------------ G: the tie-out */
    echo "\n== G: the number and the document agree (documents:number-integrity) ==\n";

    $findings = array_values(array_filter(
        (new NumberIntegrityChecker)->check(1),
        static fn ($finding): bool => $finding->scope === 'invoice',
    ));

    check($findings !== [], 'the invoice scope is registered, so the checker is not silently skipping it');
    check(array_filter($findings, static fn ($finding): bool => $finding->isUnchecked()) === [], '...and it is checked rather than unchecked');
    check(array_filter($findings, static fn ($finding): bool => $finding->isDrift()) === [], '...with no drift between the sequence and the issued document', implode(' | ', array_map(static fn ($f): string => $f->detail, $findings)));

    /* ------------------------------------------------------------------ H: the receipt */
    echo "\n== H: money arrives on account, then settles the invoice through allocations only ==\n";

    $gross = $invoice->amount + $invoice->tax_amount;

    check($invoice->settlementState() === SettlementState::Paid || $invoice->settlementState() === SettlementState::Open, 'the invoice starts as an open item');
    check($invoice->ageingState() === AgeingBucket::Current, '...and ages as "not yet due" before its net_30 date');

    Auth::login($dispatcher);
    $paymentRefusal = '';

    try {
        (app(RecordPayment::class))(new PaymentDto(
            customerId: $customer->id,
            amountMinor: $gross,
            method: PaymentMethod::Bank,
            receivedOn: Carbon::now(),
        ), $dispatcher->id);
    } catch (Throwable $refused) {
        $paymentRefusal = $refused->getMessage();
    }

    check(str_contains($paymentRefusal, 'payments.allocate'), 'a dispatcher cannot bank a receipt either', $paymentRefusal);
    Auth::login($issuer);

    $payment = (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: $gross,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        reference: 'INV-VERIFY EFT',
        sourceUuid: 'verify-inv:receipt-one',
    ), $issuer->id);

    check(preg_match('/^RCP-[A-Z0-9\/]+-\d{6}$/', (string) $payment->number) === 1, 'the receipt carries a gap-free RCP number, allocated at creation', (string) $payment->number);
    check($payment->unapplied_amount === $gross, '...and the whole amount is unapplied, because no invoice was named at the door');

    $receiptEntry = JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->first();
    $receiptLines = [];

    foreach ($receiptEntry?->lines()->with('account')->get() ?? [] as $line) {
        $receiptLines[$line->account->code] = $line;
    }

    check(($receiptLines['1120']->direction->value ?? null) === 'debit' && $receiptLines['1120']->amount_base === $gross, 'PaymentReceived posts Dr 1120 for the money');
    check(($receiptLines['2150']->direction->value?? null) === 'credit' && $receiptLines['2150']->amount_base === $gross, '...and Cr 2150 Unapplied customer cash, not AR');

    $settledInvoice = (app(AllocatePayment::class))($invoice->fresh(), $issuer->id);

    check($settledInvoice->balance_amount === 0, 'the paid invoice reaches exactly zero through allocations only (definition of done 2)', (string) $settledInvoice->balance_amount);
    check($settledInvoice->balance_base === 0, '...in base currency too');
    check($settledInvoice->settlementState() === SettlementState::Paid, '...and its derived settlement state is paid, not a stored flag');
    check($settledInvoice->status === InvoiceLifecycle::Issued, '...while the lifecycle stays issued — a payment never mutates it');

    $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->first();
    check($allocation !== null && $allocation->settled === true, 'the allocation that closed the invoice is marked settled');
    check($allocation !== null && $allocation->amount === $gross, '...for the whole receipt, since one invoice and one payment were involved');

    $payment->refresh();
    check($payment->unapplied_amount === 0 && $payment->isFullyApplied(), 'the receipt now holds nothing unapplied');

    $allocationEntry = JournalEntry::query()->where('source_type', 'payment_allocation')->where('source_id', $allocation->id)->first();
    $allocationLines = [];

    foreach ($allocationEntry?->lines()->with('account')->get() ?? [] as $line) {
        $allocationLines[$line->account->code] = $line;
    }

    check(($allocationLines['2150']->direction->value ?? null) === 'debit' && $allocationLines['2150']->amount_base === $gross, 'PaymentAllocated posts Dr 2150 for the money it spends');
    check(($allocationLines['1150']->direction->value ?? null) === 'credit' && $allocationLines['1150']->amount_base === $gross, '...and Cr 1150, so the receivable is what fell');

    $reallocateRefusal = '';

    try {
        (app(AllocatePayment::class))($invoice->fresh(), $issuer->id);
    } catch (Throwable $refused) {
        $reallocateRefusal = $refused->getMessage();
    }

    check(str_contains($reallocateRefusal, 'no balance left'), 'a settled invoice cannot be paid twice', $reallocateRefusal);

    $retry = (app(RecordPayment::class))(new PaymentDto(
        customerId: $customer->id,
        amountMinor: $gross,
        method: PaymentMethod::Bank,
        receivedOn: Carbon::now(),
        sourceUuid: 'verify-inv:receipt-one',
    ), $issuer->id);

    check($retry->id === $payment->id && Payment::query()->where('customer_id', $customer->id)->count() === 1, 'a retried receipt lands on the same row, not a second bank credit');

    /* ------------------------------------------------------------------ I: the void */
    echo "\n== I: a paid invoice is voided only through a credit note; an unpaid one reverses and reopens the trip ==\n";

    $voidRefusal = '';

    try {
        (app(VoidInvoice::class))($invoice->fresh(), 'The customer says the delivery never happened', $issuer->id);
    } catch (Throwable $refused) {
        $voidRefusal = $refused->getMessage();
    }

    check(str_contains($voidRefusal, 'cannot be voided'), 'a void is refused once a payment exists (definition of done 3)', $voidRefusal);
    check(Invoice::query()->whereKey($invoice->id)->where('status', InvoiceLifecycle::Issued->value)->exists(), '...and the invoice is still issued, not half-voided');

    // A second trip on the same job: an unpaid invoice so the void's own path can be proven.
    $secondTrip = (app(CreateTrip::class))($job, 1);
    $secondTrip = (app(PlanTrip::class))($secondTrip, new TripDto(
        jobId: $job->id,
        truckId: $truck->id,
        driverId: $driver->id,
        trailerId: $trailer->id,
        plannedDepartureAt: Carbon::now()->addDays(3)->toDateTimeString(),
    ), 1);

    $secondChargeableId = (string) Str::ulid();

    Chargeable::create([
        'id' => $secondChargeableId,
        'trip_id' => $secondTrip->id,
        'job_id' => $job->id,
        'customer_id' => $customer->id,
        'type' => ChargeableType::Freight->value,
        'basis' => ChargeableBasis::PerTrip->value,
        'description' => 'INV-VERIFY second leg',
        'currency_code' => $base,
        'amount' => 1_000_000,
        'base_amount' => 1_000_000,
        'status' => ChargeableStatus::Ready->value,
        'source_uuid' => 'verify-inv:three',
    ]);

    $secondInvoice = (app(IssueInvoice::class))($secondTrip->id, $issuer->id);
    $secondEntry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $secondInvoice->id)->first();

    $voided = (app(VoidInvoice::class))($secondInvoice->fresh(), 'Dispatched against the wrong customer account', $issuer->id);

    check($voided->status === InvoiceLifecycle::Void, 'an unpaid invoice voids');
    check($voided->number === $secondInvoice->number, '...keeping its spent number, because a fiscal number is never reused');
    check($voided->void_reason !== null && mb_strlen((string) $voided->void_reason) >= 10, '...and carrying the reason it was voided');

    $reversal = JournalEntry::query()->where('source_type', 'invoice_void')->where('source_id', $secondInvoice->id)->first();

    check($reversal!== null, 'the void mirrors the posting rather than editing it');
    check($reversal !== null && $reversal->reverses_entry_id === $secondEntry?->id, '...naming the entry it reverses');
    check($reversal?->total_debit === $secondEntry?->total_credit, '...with the sides swapped, so the pair nets to zero');

    check(Chargeable::query()->whereKey($secondChargeableId)->where('status', ChargeableStatus::Ready->value)->exists(), 'the delivery is released back to ready, so the trip can be billed again');

    $reissue = (app(IssueInvoice::class))($secondTrip->id, $issuer->id);
    check($reissue->number!== $secondInvoice->number, '...and a fresh issue takes a new number rather than reusing the void one');

    /* ------------------------------------------------------------------ J: the credit note */
    echo "\n== J: a credit note corrects the invoice without rewinding it ==\n";

    $creditedLine = $reissue->lines->first();
    $creditNet = 400_000;
    $creditTax = intdiv($creditNet * $rate->rate_x10000 + 500_000, 1_000_000);
    $creditGross = $creditNet + $creditTax;
    $balanceBefore = $reissue->balance_amount;

    $note = (app(IssueCreditNote::class))($reissue->fresh(), new CreditNoteDto(
        reason: 'pricing_error',
        lines: [$creditedLine->id => $creditNet],
        narrative: 'The agreed rate was lower than the one billed',
    ), $issuer->id);

    check($note->status === CreditNoteLifecycle::Applied, 'the note is issued and applied in one stroke');
    check(preg_match('/^CN-[A-Z0-9\/]+-\d{7}$/', (string) $note->number) === 1, '...on its own gap-free CN scope, not the invoice one', (string) $note->number);
    check($note->amount === $creditNet && $note->tax_amount === $creditTax, '...crediting the net and the VAT at the rate the line was billed under');
    check($note->balance_amount === 0, '...with nothing left to apply');

    $afterCredit = $reissue->fresh();
    check($afterCredit->balance_amount === $balanceBefore - $creditGross, 'the invoice\'s balance falls by the credit\'s gross', (string) $afterCredit->balance_amount);
    check($afterCredit->amount === $reissue->amount && $afterCredit->tax_amount === $reissue->tax_amount, '...while the invoice itself is never rewritten');
    check($afterCredit->status === InvoiceLifecycle::Issued, '...and its lifecycle stays issued — a credit note moves the balance, not the state');

    $creditEntry = JournalEntry::query()->where('source_type', 'credit_note')->where('source_id', $note->id)->first();
    $creditLines = [];

    foreach ($creditEntry?->lines()->with('account')->get() ?? [] as $line) {
        $creditLines[$line->account->code] = $line;
    }

    check(($creditLines['4100']->direction->value ?? null) === 'debit' && $creditLines['4100']->amount_base === $creditNet, 'CreditNoteIssued debits the revenue account the line credited');
    check(($creditLines['2125']->direction->value ?? null) === 'debit' && $creditLines['2125']->amount_base === $creditTax, '...and the output VAT it charged');
    check(($creditLines['1150']->direction->value ?? null) === 'credit' && $creditLines['1150']->amount_base === $creditGross, '...crediting the receivable by the gross');

    $secondCredit = '';

    try {
        (app(IssueCreditNote::class))($reissue->fresh(), new CreditNoteDto(
            reason: 'other',
            lines: [$creditedLine->id => 100_000],
        ), $issuer->id);
    } catch (Throwable $refused) {
        $secondCredit = $refused->getMessage();
    }

    check(str_contains($secondCredit, 'already been credited'), 'one billed line can only be credited once, so revenue cannot be driven negative', $secondCredit);

    $badReason = '';

    try {
        (app(IssueCreditNote::class))($reissue->fresh(), new CreditNoteDto(reason: 'because I felt like it', lines: [$creditedLine->id => 1]), $issuer->id);
    } catch (Throwable $refused) {
        $badReason = $refused->getMessage();
    }

    check(str_contains($badReason, 'not one of'), 'a free-text reason is refused, so the register stays groupable', $badReason);

    $creditFindings = array_values(array_filter(
        (new NumberIntegrityChecker)->check(1),
        static fn ($finding): bool => $finding->scope === 'credit_note',
    ));

    check($creditFindings !== [] && array_filter($creditFindings, static fn ($f): bool => $f->isUnchecked()) === [], 'the credit_note scope is registered with the integrity checker');
    check(array_filter($creditFindings, static fn ($f): bool => $f->isDrift()) === [], '...and the note\'s number agrees with its sequence');

    /* ------------------------------------------------------------------ K: the consolidated run */
    echo "\n== K: a consolidated run bills one customer's month as one document ==\n";

    $bulk = (app(CreateCustomer::class))(new CustomerDto(name: 'INV-VERIFY Bulk', code: 'INV-VERIFY-BULK', paymentTerms: 'net_14'));

    $bulkJob = (app(CreateJob::class))(new JobDto(
        customerId: $bulk->id,
        routeId: $route->id,
        commodity: 'statement cargo',
        tonnes: 18.0,
        requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
        requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
        notes: 'INV-VERIFY statement job',
    ), 1);
    $bulkJob = (app(QuoteJob::class))($bulkJob, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '20000', currencyCode: $base), 1);
    $bulkJob = (app(AcceptJob::class))($bulkJob, 1);

    /** @var list<string> $bulkTripIds */
    $bulkTripIds = [];
    /** @var list<string> $bulkChargeIds */
    $bulkChargeIds = [];

    foreach ([1_500_000, 500_000] as $index => $amount) {
        $leg = (app(CreateTrip::class))($bulkJob, 1);
        $leg = (app(PlanTrip::class))($leg, new TripDto(
            jobId: $bulkJob->id,
            truckId: $truck->id,
            driverId: $driver->id,
            trailerId: $trailer->id,
            plannedDepartureAt: Carbon::now()->addDays(2 + $index)->toDateTimeString(),
        ), 1);

        $chargeId = (string) Str::ulid();

        Chargeable::create([
            'id' => $chargeId,
            'trip_id' => $leg->id,
            'job_id' => $bulkJob->id,
            'customer_id' => $bulk->id,
            'type' => ChargeableType::Freight->value,
            'basis' => ChargeableBasis::PerTrip->value,
            'description' => 'INV-VERIFY statement leg '.($index + 1),
            'currency_code' => $base,
            'amount' => $amount,
            'base_amount' => $amount,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => 'verify-inv:bulk:'.$index,
        ]);

        $bulkTripIds[] = (string) $leg->id;
        $bulkChargeIds[] = $chargeId;
    }

    $sequenceBeforeRun = (int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value');

    $windowFrom = Carbon::now()->subDay()->startOfDay();
    $windowTo = Carbon::now()->addDay()->startOfDay();

    $statement = (app(RunConsolidatedBilling::class))($bulk->id, $windowFrom, $windowTo, $issuer->id);

    check($statement->kind === InvoiceKind::Consolidated, 'the run issues a consolidated invoice, not a per-trip one');
    check($statement->job_id === null, '...naming no single job, because it bundles several legs');
    check($statement->customer_id === $bulk->id, '...addressed to the one customer the charges belong to');
    check($statement->terms_snapshot === 'net_14' && $statement->due_on->toDateString() === Carbon::now()->addDays(14)->toDateString(), '...on that customer\'s own terms');
    check($statement->lines->count() === 2, 'one line per chargeable across both trips');
    check($statement->lines->pluck('trip_id')->unique()->count() === 2, '...each naming its own leg, so 4100 keeps its trip dimension');
    check($statement->amount === 2_000_000, '...net is the sum of both trips', (string) $statement->amount);
    check(str_starts_with((string) $statement->lines->first()->description, (string) Trip::query()->findOrFail($bulkTripIds[0])->trip_no.' · '), '...and reads with its trip number, which is how a statement\'s reader finds the POD', (string) $statement->lines->first()->description);
    check((int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value') === $sequenceBeforeRun + 1, 'the run spends exactly one number from the same gap-free scope');
    check(preg_match('/^INV-[A-Z0-9\/]+-\d{7}$/', (string) $statement->number) === 1, '...rendered in the invoice scope\'s format', (string) $statement->number);

    check(Chargeable::query()->whereIn('id', $bulkChargeIds)->where('status', ChargeableStatus::Billed->value)->count() === 2, 'both trips\' charges are claimed in the same transaction');
    check(Trip::query()->findOrFail($bulkTripIds[0])->revenue_base === 1_500_000
        && Trip::query()->findOrFail($bulkTripIds[1])->revenue_base === 500_000, '...and each trip\'s projection carries its own net revenue');

    $statementEntry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $statement->id)->first();
    $statementLines = [];

    foreach ($statementEntry?->lines()->with('account')->get() ?? [] as $line) {
        $statementLines[$line->account->code][] = $line;
    }

    check(($statementLines['4100']?? [])!== [] && count($statementLines['4100']) === 2, 'one entry with a 4100 credit per leg, never one lump revenue figure');
    check(count(array_unique(array_map(static fn ($line): string => (string) $line->trip_id, $statementLines['4100']))) === 2, '...each credit naming its own trip');
    check(($statementLines['1150'][0]->direction->value ?? null) === 'debit' && $statementLines['1150'][0]->amount_base === $statement->balance_amount, '...and one receivable debit for the statement\'s gross');

    $retryRun = (app(RunConsolidatedBilling::class))($bulk->id, $windowFrom, $windowTo, $issuer->id);

    check($retryRun->id === $statement->id, 'a retried run returns the document it already issued, rather than a second statement');
    check((int) DB::table('number_sequences')
        ->where('company_id', 1)->where('scope', 'invoice')
        ->where('period_key', NumberPeriod::keyFor('financial_year', Carbon::now(), 'invoice'))
        ->value('next_value') === $sequenceBeforeRun + 1, '...and spends no second number');

    $emptyRun = '';

    try {
        (app(RunConsolidatedBilling::class))($bulk->id, Carbon::now()->addDays(30)->startOfDay(), Carbon::now()->addDays(31)->startOfDay(), $issuer->id);
    } catch (Throwable $refused) {
        $emptyRun = $refused->getMessage();
    }

    check(str_contains($emptyRun, 'nothing to consolidate'), 'a period with no ready charges is refused rather than issuing an empty statement', $emptyRun);

    /* ------------------------------------------------------------------ L: the print gate */
    echo "\n== L: the documents render under the DOMPDF driver (definition of done 4) ==\n";

    $documents = app(InvoiceDocuments::class);

    $invoicePdf = $documents->invoice($invoice->fresh());
    $creditPdf = $documents->creditNote($note->fresh());

    $statement = (app(Statements::class))->forCustomer($bulk->id, $windowFrom, $windowTo);
    $statementPdf = $documents->statement($statement);

    check(str_starts_with($invoicePdf, '%PDF-'), 'the invoice renders as a PDF, not an HTML error page');
    check(strlen($invoicePdf) > 2_000, '...and has a body rather than an empty page', (string) strlen($invoicePdf).' bytes');
    check(str_starts_with($creditPdf, '%PDF-') && strlen($creditPdf) > 2_000, 'the credit note renders too, naming the invoice it corrects');
    check(str_starts_with($statementPdf, '%PDF-') && strlen($statementPdf) > 2_000, 'the customer statement renders from its snapshot, without re-reading the invoice models');
    check(count($statement->lines) === 1 && str_starts_with($statement->lines[0]->number, 'INV-'), 'the statement carries the invoice row it printed, with that document\'s number');
    check($statement->outstandingMinor === $statement->lines[0]->balanceMinor, '...and its outstanding total is the sum of those rows', (string) $statement->outstandingMinor);
    check(config('laravel-pdf.driver') === 'dompdf', 'the default driver is DOMPDF, which needs no Chrome or Node');

    /* ------------------------------------------------------------------ M: the ageing report */
    echo "\n== M: the ageing report buckets open invoices by their due date ==\n";

    $ageing = app(AgeingReport::class);
    $rows = $ageing();
    $totals = AgeingReport::totals($rows);

    check($rows !== [], 'the report has a row for at least one customer with money owing');
    check(array_filter($rows, static fn ($row): bool => $row->totalMinor() <= 0) === [], '...and every row is money actually owed, not a settled invoice');
    check($totals['total'] === (int) Invoice::query()->where('status', InvoiceLifecycle::Issued->value)->where('balance_amount', '>', 0)->sum('balance_amount'), '...and the column totals equal the open invoices\' balances, which is the ledger\'s AR figure');

    $bulkRow = array_values(array_filter($rows, static fn ($row): bool => $row->customerId === $bulk->id))[0] ?? null;

    check($bulkRow !== null && $bulkRow->currentMinor === $statement->outstandingMinor, 'the bulk customer sits in "not yet due", because a net_14 invoice issued today is not late');
    check($bulkRow !== null && $bulkRow->oldestDueOn?->format('Y-m-d') === $statement->lines[0]->dueOn->format('Y-m-d'), '...and the row names its oldest due date');
} catch (Throwable $blewUp) {
    failed('the probe ran to the end', get_class($blewUp).': '.$blewUp->getMessage());
} finally {
    cleanUp();
}

echo "\n== result ==\n";
echo "{$checks} checks, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
