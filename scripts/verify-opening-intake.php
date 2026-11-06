<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\BillCategory;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\BillLine;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\LoanInstallment;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\SupplierCategory;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\OdometerReading;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Plausibility;
use App\Domain\Fuel\Actions\CreateBulkTank;
use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\BulkTankMovement;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\RecordOpeningReceivable;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Cutover\Actions\ApplyCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\ImportCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\PostOpeningTrialBalance;
use App\Domain\Kernel\Cutover\Actions\ReverseOpeningTrialBalance;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverErrorReport;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\People\Actions\CreateDriver;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0.5 completion, slice 8a — the opening party detail arrives as documents.
 *
 * The trial balance has carried this company's opening figures since, and it still cannot
 * carry these: `1150` wants a customer and the payable tie-out walks every bill. So the two new
 * datasets write **invoices and bills**, and this run proves the whole claim of that decision:
 *
 *   the demo file the accountant is handed validates with an **empty error report** (the design's own
 *   DoD wording, checked literally); applying it puts one balanced entry per document on the ledger
 *   with the party dimension that entry demands; the ageing report and the per-bill tie-out find the
 *   opening items exactly as they find live ones; and applying the same file again changes nothing,
 *   because a row's natural key is the legacy document number it carries.
 *
 * Then the rule that keeps the two opening doors from both posting `1150`: a trial balance line for
 * an account an applied detail batch owns is refused, and a detail batch arriving after a trial
 * balance that already carries `2110` is refused — reversed, it is welcome again.
 *
 * Every row is named OPENCUT-/OPENSUP-/OPENEMP-/"verify-opening-*" and is removed at the end through
 * the ledger barrier, with the fiscal sequences rewound to the highest document still on disk.
 *
 * Sections H and I are slice 8c: the cash a driver still holds, arriving as a receivable with
 * the driver dimension `1160` insists on, and the financing book arriving as a plan the forecast can
 * read — with the whole point being that the second of those files posts nothing.
 *
 * Run: php scripts/verify-opening-intake.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness posts opening journals to the ledger. Dev only.\n";
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

/** The first sentence of a batch's refusal, which is what a screen prints above the table. */
function batchError(CutoverBatch $batch): string
{
    return (string) ($batch->batch_errors[0] ?? '');
}

/** The errors the engine put beside one line number. */
function rowErrors(CutoverBatch $batch, int $line): string
{
    $row = $batch->rows()->where('line_number', $line)->first();

    return $row === null ? '(no such line)' : implode(' | ', array_map(static fn ($e): string => (string) $e, $row->errors ?? []));
}

CurrentCompany::force(1);

$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', 'owner')
    ->select('users.id')
    ->first();

/** @var User|null $owner */
$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner seat. Run: php artisan fleet:setup-owner.\n");
    exit(2);
}

Auth::login($owner);

$demoDir = __DIR__.'/../docs/cutover/demo';
$demoReceivables = (string) file_get_contents($demoDir.'/opening-receivables.csv');
$demoPayables = (string) file_get_contents($demoDir.'/opening-payables.csv');
$demoAdvances = (string) file_get_contents($demoDir.'/opening-driver-advances.csv');
$demoLoans = (string) file_get_contents($demoDir.'/opening-loans.csv');

$sweep = function () use ($owner): void {
    $customerIds = DB::table('customers')->where('company_id', 1)->where('code', 'like', 'OPENCUT-%')->pluck('id')->all();
    $supplierIds = DB::table('suppliers')->where('company_id', 1)->where('code', 'like', 'OPENSUP-%')->pluck('id')->all();

    $invoiceIds = $customerIds === [] ? [] : DB::table('invoices')->whereIn('customer_id', $customerIds)->pluck('id')->all();
    $billIds = $supplierIds === [] ? [] : DB::table('bills')->whereIn('supplier_id', $supplierIds)->pluck('id')->all();

    // The physical-truth fixtures: two trucks with an odometer history and two yard tanks
    // with opening litres. Their names are distinctive so a crashed run cannot collide with a fleet.
    $truckIds = DB::table('trucks')->where('company_id', 1)->where('registration_no', 'like', 'B 9001 OV%')->pluck('id')->all();
    $tankIds = DB::table('bulk_tanks')->where('company_id', 1)->where('name', 'like', 'OPENVERIFY %')->pluck('id')->all();
    $movementIds = $tankIds === [] ? [] : DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $tankIds)->pluck('id')->all();

    // The fixtures: two employees who are drivers, one who is only an employee (the refusal),
    // the advances they hold on the ledger, and the facilities whose plan this run rebuilds.
    $employeeIds = DB::table('employees')->where('company_id', 1)->where('employee_no', 'like', 'OPENEMP-%')->pluck('id')->all();
    $driverIds = $employeeIds === [] ? [] : DB::table('drivers')->whereIn('employee_id', $employeeIds)->pluck('id')->all();

    $loanIds = Loan::query()
        ->where('company_id', 1)
        ->where('source_uuid', 'like', 'opening-loan:%')
        ->where(static function ($query) use ($truckIds, $supplierIds): void {
            $query->whereIn('truck_id', $truckIds === [] ? [0] : $truckIds)
                ->orWhereIn('supplier_id', $supplierIds === [] ? [0] : $supplierIds);
        })
        ->pluck('id')
        ->all();

    $entryIds = array_merge(
        $invoiceIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'invoice')->whereIn('source_id', $invoiceIds)->pluck('id')->all(),
        $billIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'bill')->whereIn('source_id', $billIds)->pluck('id')->all(),
        $movementIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'bulk_tank_opening')->whereIn('source_id', $movementIds)->pluck('id')->all(),
        $driverIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'cutover_advance')->whereIn('source_id', array_map(static fn ($id): string => (string) $id, $driverIds))->pluck('id')->all(),
        DB::table('cutover_batches')->where('company_id', 1)->where('original_filename', 'like', 'verify-opening-%')->pluck('journal_entry_id')->filter()->all(),
        DB::table('cutover_batches')->where('company_id', 1)->where('original_filename', 'like', 'verify-opening-%')->pluck('reversal_entry_id')->filter()->all(),
    );

    LedgerBarrier::withLiftedGuard('verify: remove the opening-intake run', $owner->id, function () use ($entryIds, $invoiceIds, $billIds, $customerIds, $supplierIds, $truckIds, $tankIds, $movementIds, $employeeIds, $driverIds, $loanIds): void {
        if ($entryIds !== []) {
            // Mirrors first: a reversal names the entry it undoes, and the self-FK will not let the
            // parent go while its mirror still points at it (the cleanup lesson).
            $ordered = DB::table('gl_journal_entries')->whereIn('id', $entryIds)->orderByDesc('reverses_entry_id')->pluck('id')->all();

            foreach ($ordered as $entryId) {
                DB::table('gl_journal_lines')->where('entry_id', $entryId)->delete();
                DB::table('gl_journal_entries')->where('id', $entryId)->delete();
            }
        }

        DB::table('operational_events')->whereIn('aggregate_id', array_merge($invoiceIds, $billIds))->delete();

        if ($billIds !== []) {
            DB::table('supplier_payment_allocations')->whereIn('bill_id', $billIds)->delete();
            DB::table('bill_lines')->whereIn('bill_id', $billIds)->delete();
            DB::table('bills')->whereIn('id', $billIds)->delete();
        }

        if ($invoiceIds !== []) {
            $noteIds = DB::table('credit_notes')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();

            if ($noteIds !== []) {
                DB::table('credit_note_lines')->whereIn('credit_note_id', $noteIds)->delete();
                DB::table('credit_notes')->whereIn('id', $noteIds)->delete();
            }

            DB::table('payment_allocations')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        }

        $batchIds = DB::table('cutover_batches')->where('company_id', 1)->where('original_filename', 'like', 'verify-opening-%')->pluck('id')->all();

        if ($batchIds !== []) {
            DB::table('cutover_rows')->whereIn('cutover_batch_id', $batchIds)->delete();
            DB::table('operational_events')->where('aggregate_type', 'cutover_batch')->whereIn('aggregate_id', array_map(static fn ($id): string => (string) $id, $batchIds))->delete();
            DB::table('cutover_batches')->whereIn('id', $batchIds)->delete();
        }

        // The rows: the readings, then the tank movements and the tanks themselves, then the
        // trucks the readings belonged to. A tank's book is a cache over its movements, so both go.
        if ($truckIds !== []) {
            DB::table('odometer_readings')->whereIn('truck_id', $truckIds)->delete();
        }

        if ($movementIds !== []) {
            DB::table('bulk_tank_movements')->whereIn('id', $movementIds)->delete();
        }

        if ($tankIds !== []) {
            DB::table('bulk_tanks')->whereIn('id', $tankIds)->delete();
        }

        if ($truckIds !== []) {
            DB::table('fuel_tanks')->whereIn('truck_id', $truckIds)->delete();
            DB::table('trucks')->whereIn('id', $truckIds)->delete();
        }

        // The facilities first (their instalment rows cascade with them, and a real loan the
        // office raised by hand is not in this set — the scope is the fixture truck or lender), then
        // the driver and the employee the advance was named against.
        if ($loanIds !== []) {
            DB::table('loans')->whereIn('id', $loanIds)->delete();
        }

        if ($driverIds !== []) {
            // The advance's aggregate id is `driver/slip`, so each driver's own events are matched by
            // their prefix rather than by a SQL expression a different engine would read differently.
            foreach ($driverIds as $driverId) {
                DB::table('operational_events')
                    ->where('aggregate_type', 'driver_advance')
                    ->where('aggregate_id', 'like', $driverId.'/%%')
                    ->delete();
            }

            DB::table('drivers')->whereIn('id', $driverIds)->delete();
        }

        if ($employeeIds !== []) {
            DB::table('employees')->whereIn('id', $employeeIds)->delete();
        }

        DB::table('customers')->whereIn('id', $customerIds)->delete();
        DB::table('suppliers')->whereIn('id', $supplierIds)->delete();
    });

    // The reference column is named per table — invoices and bills keep `number`, the financing book
    // calls the same allocated string `reference` — so the filter has to use the column it
    // reads, or rewinding the loan sequence would be a query against a column that does not exist.
    foreach ([['invoice', 'invoices', 'number'], ['bill', 'bills', 'number'], ['loan', 'loans', 'reference']] as [$scope, $table, $column]) {
        foreach (DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->get(['id', 'period_key']) as $sequence) {
            $highest = DB::table($table)->where('company_id', 1)->where($column, 'like', '%'.$sequence->period_key.'%')
                ->pluck($column)
                ->map(static function (?string $number): int {
                    $dash = $number === null ? false : strrpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? (string) $number : substr((string) $number, $dash + 1));
                })
                ->max() ?? 0;

            DB::table('number_sequences')->where('id', $sequence->id)->update(['next_value' => $highest + 1]);
        }
    }
};

$sweep();

try {
    $base = CurrentCompany::model()->base_currency_code;

    echo "== the fixture: the parties the opening files will name ==\n";

    foreach ([
        ['OPENCUT-1', 'OPENCUT Open Item One', 'net_30'],
        ['OPENCUT-2', 'OPENCUT Corridor Customer', 'net_14'],
        ['OPENCUT-3', 'OPENCUT Sundry Account', 'cash'],
    ] as [$code, $name, $terms]) {
        (app(CreateCustomer::class))(new CustomerDto(name: $name, code: $code, paymentTerms: $terms));
    }

    foreach ([
        ['OPENSUP-1', 'OPENSUP Garage', 'workshop'],
        ['OPENSUP-2', 'OPENSUP Clearing Agent', 'customs'],
        ['OPENSUP-3', 'OPENSUP Insurer', 'insurance'],
    ] as [$code, $name, $category]) {
        (app(CreateSupplier::class))(new SupplierDto(
            name: $name,
            code: $code,
            category: SupplierCategory::tryFrom($category) ?? SupplierCategory::Other,
            paymentTerms: PaymentTerms::DEFAULT,
        ));
    }

    check(Customer::query()->whereIn('code', ['OPENCUT-1', 'OPENCUT-2', 'OPENCUT-3'])->count() === 3, 'three debtors are on the register');
    check(Supplier::query()->whereIn('code', ['OPENSUP-1', 'OPENSUP-2', 'OPENSUP-3'])->count() === 3, '...and three creditors');

    // The fixtures: two trucks that already exist (so the readings file is history, not an
    // overwrite of the projection) and two yard tanks with capacity for the litres the demo carries.
    foreach ([
        ['B 9001 OV1', 'T-9001-1', 'VIN9001000000000000001'],
        ['B 9001 OV2', 'T-9001-2', 'VIN9001000000000000002'],
    ] as [$registration, $fleetNo, $vin]) {
        (app(CreateTruck::class))(new TruckDto(
            registrationNo: $registration,
            fleetNo: $fleetNo,
            vin: $vin,
            make: 'Probe',
            model: 'Opening',
            odometerKm: 10_000,
        ));
    }

    check(Truck::query()->where('registration_no', 'like', 'B 9001 OV%')->count() === 2, '...and two trucks whose odometer history can be imported');

    foreach (['OPENVERIFY Yard Tank', 'OPENVERIFY Border Tank'] as $tankName) {
        (app(CreateBulkTank::class))($tankName, 30_000);
    }

    check(BulkTank::query()->where('name', 'like', 'OPENVERIFY %')->count() === 2, '...and two yard tanks empty enough to be opened');

    // The fixtures: two employees who are also drivers, because `1160` cannot be posted without
    // the driver dimension, and a third who is only an employee — the row the advances file has
    // to refuse by name rather than drop silently into a receivable nobody owes.
    $fixtureEmployeeIds = [];

    foreach ([
        ['OPENEMP-1', 'OPENEMP First Driver'],
        ['OPENEMP-2', 'OPENEMP Second Driver'],
        ['OPENEMP-3', 'OPENEMP Office Clerk'],
    ] as [$employeeNo, $name]) {
        $fixtureEmployeeIds[$employeeNo] = (int) (app(CreateEmployee::class))(
            new EmployeeDto(employeeNo: $employeeNo, name: $name, hiredOn: '2025-01-06'),
        )->id;
    }

    foreach (['OPENEMP-1', 'OPENEMP-2'] as $employeeNo) {
        (app(CreateDriver::class))(new DriverDto(employeeId: $fixtureEmployeeIds[$employeeNo]));
    }

    check(Employee::query()->where('employee_no', 'like', 'OPENEMP-%')->count() === 3, '...three employees, and only two of them hold a slip');
    check(Driver::query()->whereIn('employee_id', array_values($fixtureEmployeeIds))->count() === 2, '...enrolled as drivers, which is what gives an advance its dimension');

    $import = app(ImportCutoverBatch::class);
    $apply = app(ApplyCutoverBatch::class);

    /* ---------------------------------------------------------------- A: the demo file */
    echo "\n== A: The design's own words — \"import error report is empty for the demo file\" ==\n";

    $arBatch = $import->handle(CutoverDataset::OpeningReceivables, $demoReceivables, 'verify-opening-receivables-demo.csv', $owner->id);

    check($arBatch->status === CutoverBatchStatus::Validated, 'the demo receivables file validated', batchError($arBatch).' errors='.$arBatch->error_row_count);
    check((int) $arBatch->row_count === 4 && (int) $arBatch->valid_row_count === 4, '...four open items, every one of them taken', $arBatch->row_count.'/'.$arBatch->valid_row_count);
    check((int) $arBatch->error_row_count === 0, '...and no row carries a problem');
    $arReport = CutoverErrorReport::build($arBatch);
    check(trim($arReport) !== '' && count(array_filter(explode("\n", $arReport))) === 1, '...the downloadable error report carries its header and nothing else — empty in the DoD\'s own words', str_replace("\n", '\\n', $arReport));

    $applied = $apply->handle($arBatch, $owner->id);

    check($applied->status === CutoverBatchStatus::Applied, 'the Owner applied it (the second half)');

    $invoices = Invoice::query()->where('kind', InvoiceKind::Opening->value)->where('source_uuid', 'like', 'opening:OPENCUT-%')->orderBy('id')->get();

    check($invoices->count() === 4, '...four opening documents exist, one per row', (string) $invoices->count());
    check($invoices->every(static fn (Invoice $invoice): bool => $invoice->status === InvoiceLifecycle::Issued
        && $invoice->number !== null
        && (int) $invoice->tax_amount === 0
        && (int) $invoice->tax_base === 0), '...each issued and numbered, and no one of them charges VAT (it was filed in the old books)');

    $zar = $invoices->first(static fn (Invoice $invoice): bool => $invoice->currency_code === 'ZAR');

    check($zar !== null && (int) $zar->balance_amount === 90_000_000
        && (int) $zar->balance_base === 48_690_000
        && $zar->fx_rate_source === FxRateSource::OpeningBalance->value
        && (float) $zar->fx_rate === 0.541,
        '...the corridor item stayed in Rand, converted once at the rate the file stated (900 000.00 ZAR → 486 900.00 '.$base.')',
        $zar === null ? 'no ZAR row' : $zar->balance_amount.'/'.$zar->balance_base.'/'.$zar->fx_rate);

    /* ------------------------------------------------------------------ B: the ledger */
    echo "\n== B: one entry per document, the party on the line the account demands ==\n";

    $ledger = app(LedgerService::class);
    $balanced = 0;
    $dimensioned = 0;

    foreach ($invoices as $invoice) {
        $net1150 = 0;
        $net3010 = 0;

        foreach ($ledger->totalsByAccount(['invoice_id' => $invoice->id]) as $turnover) {
            if ($turnover->accountCode === '1150') {
                $net1150 += $turnover->signedDebit();
            }

            if ($turnover->accountCode === '3010') {
                $net3010 += $turnover->signedDebit();
            }
        }

        if ($net1150 === (int) $invoice->balance_base && $net3010 === -$net1150) {
            $balanced++;
        }

        // 1150 cannot be posted without a customer (the mask); the ledger would have refused the
        // whole batch if the document line had lost its debtor.
        if (DB::table('gl_journal_lines')
            ->whereIn('entry_id', DB::table('gl_journal_entries')->where('source_type', 'invoice')->where('source_id', $invoice->id)->pluck('id'))
            ->where('account_id', DB::table('gl_accounts')->where('code', '1150')->where('company_id', 1)->value('id'))
            ->whereNotNull('customer_id')
            ->exists()) {
            $dimensioned++;
        }
    }

    check($balanced === 4, '...every opening document debits 1150 and credits 3010 with its own base figure, and nothing else', $balanced.'/4');
    check($dimensioned === 4, '...and the receivable line names the customer the account asks for', $dimensioned.'/4');

    $ledger1150 = (int) DB::table('gl_journal_lines')
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.account_id')
        ->where('gl_accounts.code', '1150')
        ->whereIn('gl_journal_lines.invoice_id', $invoices->pluck('id')->all())
        ->sum(DB::raw("case when gl_journal_lines.direction = 'debit' then gl_journal_lines.amount_base else -gl_journal_lines.amount_base end"));

    $paper1150 = (int) $invoices->sum('balance_base');

    check($ledger1150 === $paper1150 && $paper1150 > 0, '...so the tie-out agrees on day one: ledger 1150 equals Σ invoice balances', $ledger1150.' vs '.$paper1150);

    $ageingRows = app(AgeingReport::class)(Carbon::now());
    $ageingTotal = array_sum(array_map(static fn ($row): int => $row->totalMinor(), $ageingRows));

    check($ageingTotal >= $paper1150, '...and the ageing report already counts them: an opening balance with no bucket is an opening balance nobody chases', (string) $ageingTotal);

    $overdue = array_values(array_filter($ageingRows, static fn ($row): bool => $row->overdueMinor() > 0
        && in_array((string) $row->customerCode, ['OPENCUT-1', 'OPENCUT-2', 'OPENCUT-3'], true)));

    check($overdue !== [], '...with the June document read as overdue on the day it arrives, because its legacy date was kept', (string) count($overdue));

    /* ------------------------------------------------------------------ C: retry */
    echo "\n== C: applying the same file again changes nothing (the natural key) ==\n";

    $again = $import->handle(CutoverDataset::OpeningReceivables, $demoReceivables, 'verify-opening-receivables-retry.csv', $owner->id);
    $apply->handle($again, $owner->id);

    check(Invoice::query()->where('source_uuid', 'like', 'opening:OPENCUT-%')->count() === 4, '...the four documents are still four, not eight', (string) Invoice::query()->where('source_uuid', 'like', 'opening:OPENCUT-%')->count());
    check(DB::table('gl_journal_entries')->where('source_type', 'invoice')->whereIn('source_id', $invoices->pluck('id')->all())->count() === 4, '...and no second entry was posted for any of them');

    /* ------------------------------------------------------------- D: the payables side */
    echo "\n== D: the supplier's open bills, whose debit side is equity ==\n";

    $apBatch = $import->handle(CutoverDataset::OpeningPayables, $demoPayables, 'verify-opening-payables-demo.csv', $owner->id);

    check($apBatch->status === CutoverBatchStatus::Validated, 'the demo payables file validated too', batchError($apBatch));
    $apReport = CutoverErrorReport::build($apBatch);
    check(count(array_filter(explode("\n", $apReport))) === 1, '...with an error report that carries only its header (the DoD, both files)', str_replace("\n", '\\n', $apReport));

    $apply->handle($apBatch, $owner->id);

    $bills = Bill::query()->where('source_uuid', 'like', 'opening-bill:OPENSUP-%')->orderBy('id')->get();

    check($bills->count() === 4, '...four recorded bills exist', (string) $bills->count());
    check($bills->every(static fn (Bill $bill): bool => (int) $bill->tax_amount === 0 && (int) $bill->balance_amount === (int) $bill->amount), '...none claims VAT, and each still owes its whole amount');

    $lines = BillLine::query()->whereIn('bill_id', $bills->pluck('id')->all())->get();

    check($lines->count() === 4 && $lines->every(static fn (BillLine $line): bool => $line->category === BillCategory::Opening && $line->expense_account_code === '3010'),
        '...and the one line each bill carries names the account the money really went to: owner capital, not an expense',
        $lines->count().' lines, '.implode('/', $lines->pluck('expense_account_code')->unique()->all()));

    $billTies = 0;

    foreach ($bills as $bill) {
        $net = 0;

        foreach ($ledger->totalsByAccount(['bill_id' => $bill->id]) as $turnover) {
            if ($turnover->accountCode === '2110') {
                $net += $turnover->signedDebit();
            }
        }

        if ((int) $bill->balance_amount === -$net) {
            $billTies++;
        }
    }

    check($billTies === 4, '...the per-bill payable tie-out holds for every opening bill exactly as it does for a live one', $billTies.'/4');

    /* -------------------------------------------------------- E: the two doors, one opener */
    echo "\n== E: one account, one opener (the cross-check) ==\n";

    $tbWithReceivable = "account_code,debit,credit,description\n1150,33085.50,,receivables control\n3010,,33085.50,opening equity\n";
    $tbBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $tbWithReceivable, 'verify-opening-tb-after-detail.csv', $owner->id);

    check($tbBatch->status === CutoverBatchStatus::Rejected, 'a trial balance cannot post 1150 once the receivables intake did', batchError($tbBatch));
    check(str_contains(rowErrors($tbBatch, 2), 'already opened by the') && str_contains(rowErrors($tbBatch, 2), 'Opening receivables'),
        '...the row says which intake owns it and what to do about it', rowErrors($tbBatch, 2));

    // And the other way round: a control total committed first, then the detail file arriving.
    $tbFirst = "account_code,debit,credit,description\n1170,,0.01,scratch\n2110,,0.01,payables control to be replaced\n";
    $tbFirstBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $tbFirst, 'verify-opening-tb-first.csv', $owner->id);

    if ($tbFirstBatch->status !== CutoverBatchStatus::Validated) {
        // 2110 carries no dimension mask, so the file is legal: it is the *second* door this checks.
        check(true, 'the pre-committed trial balance took the control line (2110 has no dimension mask)', batchError($tbFirstBatch));
    } else {
        $posted = app(PostOpeningTrialBalance::class)->handle($tbFirstBatch, CarbonImmutable::now()->startOfDay(), $owner->id);

        check($posted->status === CutoverBatchStatus::Posted, '...and committed to the ledger before the detail file arrived');

        $latePayables = $import->handle(
            CutoverDataset::OpeningPayables,
            "supplier_code,reference,amount,due_on\nOPENSUP-3,LATE-001,100.00,2026-12-31\n",
            'verify-opening-payables-after-tb.csv',
            $owner->id,
        );

        check($latePayables->status === CutoverBatchStatus::Rejected, '...the detail file is then refused at batch level: 2110 already has an opener', batchError($latePayables));
        check(str_contains(batchError($latePayables), 'already on the ledger'), '...naming the committed file rather than a rule', batchError($latePayables));

        $reversed = app(ReverseOpeningTrialBalance::class)->handle($posted, 'verify: handing the account to the party-level intake', $owner->id);

        check($reversed->status === CutoverBatchStatus::Reversed, '...reversing that commit frees the account');

        $afterReverse = $import->handle(
            CutoverDataset::OpeningPayables,
            "supplier_code,reference,amount,due_on\nOPENSUP-3,LATE-001,100.00,2026-12-31\n",
            'verify-opening-payables-after-reversal.csv',
            $owner->id,
        );

        check($afterReverse->status === CutoverBatchStatus::Validated, '...and the same detail file is welcome afterwards — the guard is about state, not suspicion', batchError($afterReverse));
    }

    /* ------------------------------------------------------------------ F: refusals */
    echo "\n== F: the refusals that keep a bad file from becoming a bad balance sheet ==\n";

    $bad = implode("\n", [
        'customer_code,reference,amount,due_on,issued_on,currency_code,fx_rate',
        'OPENCUT-NONE,LEG-X1,100.00,2026-12-01,,,',
        'OPENCUT-1,,100.00,2026-12-01,,,',
        'OPENCUT-1,LEG-X3,-5.00,2026-12-01,,,',
        'OPENCUT-1,LEG-X4,100.00,2026-05-01,2026-06-01,,',
        'OPENCUT-2,LEG-X5,100.00,2026-12-01,,ZAR,',
        'OPENCUT-1,LEG-X6,100.00,2026-12-01,,,',
        'OPENCUT-1,LEG-X6,400.00,2026-12-01,,,',
        'OPENCUT-3,LEG-X7,100.00,2026-12-01,,'.$base.',2.5',
    ]);

    $badBatch = $import->handle(CutoverDataset::OpeningReceivables, $bad, 'verify-opening-receivables-bad.csv', $owner->id);

    check($badBatch->status === CutoverBatchStatus::Rejected, 'the bad file is rejected, not half-applied');
    check(str_contains(rowErrors($badBatch, 2), 'not in the register'), '...a debtor the customers file has not imported is named', rowErrors($badBatch, 2));
    check(str_contains(rowErrors($badBatch, 3), 'Legacy document number'), '...and so is a row with no natural key', rowErrors($badBatch, 3));
    check(str_contains(rowErrors($badBatch, 4), 'positive amount'), '...a negative amount is refused, not netted against the balance', rowErrors($badBatch, 4));
    check(str_contains(rowErrors($badBatch, 5), 'before the due date'), '...a document cannot fall due before it was issued', rowErrors($badBatch, 5));
    check(str_contains(rowErrors($badBatch, 6), 'needs the rate'), '...a Rand row with no rate is refused rather than booked at a guess', rowErrors($badBatch, 6));
    check(str_contains(rowErrors($badBatch, 8), 'already carries'), '...the same legacy document cannot appear twice in one file', rowErrors($badBatch, 8));
    check(str_contains(rowErrors($badBatch, 9), 'rate other than 1'), '...and a base row cannot state a rate at all', rowErrors($badBatch, 9));

    $badPayables = $import->handle(
        CutoverDataset::OpeningPayables,
        "supplier_code,reference,amount,due_on,currency_code\nOPENSUP-1,LEG-Z1,100.00,2026-12-01,ZAR\n",
        'verify-opening-payables-foreign.csv',
        $owner->id,
    );

    check($badPayables->status === CutoverBatchStatus::Rejected
        && str_contains(rowErrors($badPayables, 2), 'booked in'),
        '...and a Rand supplier bill is refused at the row, the way the design\'s own door refuses it', rowErrors($badPayables, 2));

    check(Invoice::query()->where('source_uuid', 'like', 'opening:OPENCUT-%')->count() === 4, '...none of which reached the register: the good four are still the only ones');

    refuses('a foreign opening receivable with no rate is refused by the door itself too', function () use ($owner): void {
        app(RecordOpeningReceivable::class)->execute(
            customerId: (int) Customer::query()->where('code', 'OPENCUT-2')->value('id'),
            amountMinor: 100_000,
            issuedOn: Carbon::now(),
            dueOn: Carbon::now()->addDays(30),
            legacyReference: 'DIRECT-1',
            currencyCode: 'ZAR',
            actorId: $owner->id,
        );
    }, 'needs the rate');

    $record = app(RecordOpeningReceivable::class);
    $openItem = static fn (): Invoice => $record->execute(
        customerId: (int) Customer::query()->where('code', 'OPENCUT-1')->value('id'),
        amountMinor: 250_000,
        issuedOn: Carbon::now()->subDays(10),
        dueOn: Carbon::now()->addDays(20),
        legacyReference: 'DIRECT-OK',
        actorId: $owner->id,
    );

    $first = $openItem();
    $second = $openItem();

    check($first->id === $second->id && $first->number === $second->number, 'the door itself replays onto the document it already made, never a second one (the natural key)', $first->number.' / '.$second->number);
    check(DB::table('gl_journal_entries')->where('source_type', 'invoice')->where('source_id', $first->id)->count() === 1, '...and it posted once', (string) DB::table('gl_journal_entries')->where('source_type', 'invoice')->where('source_id', $first->id)->count());

    /* ------------------------------------------------------- G: the physical truth */
    echo "\n== G: the odometer history and the litres in the yard ==\n";

    $odoFile = (string) file_get_contents($demoDir.'/opening-odometer-readings.csv');
    $odoBatch = $import->handle(CutoverDataset::OpeningOdometerReadings, $odoFile, 'verify-opening-odometer-demo.csv', $owner->id);

    check($odoBatch->status === CutoverBatchStatus::Validated, 'the demo odometer file validates', batchError($odoBatch).' | '.rowErrors($odoBatch, 2));
    check(count(array_filter(explode("\n", CutoverErrorReport::build($odoBatch)))) === 1, '...with an error report that carries only its header');

    $apply->handle($odoBatch, $owner->id);

    $truckOne = Truck::query()->where('registration_no', 'B 9001 OV1')->firstOrFail();
    $truckTwo = Truck::query()->where('registration_no', 'B 9001 OV2')->firstOrFail();

    $readings = OdometerReading::query()->whereIn('truck_id', [$truckOne->id, $truckTwo->id])->orderBy('read_on')->get();

    check($readings->count() === 4, '...four readings on two trucks arrived as history, not as an overwrite', (string) $readings->count());
    check($readings->every(static fn (OdometerReading $reading): bool => $reading->source === OdometerSource::Cutover), '...each marked as the cutover intake, which is the trust a typed office correction does not get');

    check((int) $truckOne->odometer_km === 187_540
        && (float) $truckOne->engine_hours_10_2 >= 10_142.25,
        '...and each truck projection climbed to the largest reading in the file',
        $truckOne->odometer_km.'/'.(string) $truckOne->engine_hours_10_2);

    $backwards = OdometerReading::query()->where('truck_id', $truckTwo->id)->where('odometer_km', 96_285)->first();

    check($backwards !== null && $backwards->plausibility === Plausibility::Suspect,
        '...the reading that ran backwards was kept and marked suspect — the drop is the incident, not something to overwrite',
        $backwards === null ? 'row missing' : (string) $backwards->plausibility->value);
    check((int) Truck::query()->find($truckTwo->id)->odometer_km === 96_310, '...and the second truck\'s projection stayed at the higher figure, because a suspect row never drags it back');

    $odoRetry = $import->handle(CutoverDataset::OpeningOdometerReadings, $odoFile, 'verify-opening-odometer-retry.csv', $owner->id);
    $apply->handle($odoRetry, $owner->id);

    check(OdometerReading::query()->whereIn('truck_id', [$truckOne->id, $truckTwo->id])->count() === 4,
        '...and applying the same history twice writes no second reading',
        (string) OdometerReading::query()->whereIn('truck_id', [$truckOne->id, $truckTwo->id])->count());

    $tankFile = (string) file_get_contents($demoDir.'/opening-tank-stock.csv');
    $tankBatch = $import->handle(CutoverDataset::OpeningTankStock, $tankFile, 'verify-opening-tank-demo.csv', $owner->id);

    check($tankBatch->status === CutoverBatchStatus::Validated, 'the demo tank file validates', batchError($tankBatch).' | '.rowErrors($tankBatch, 2));

    $apply->handle($tankBatch, $owner->id);

    $openings = BulkTankMovement::query()->where('kind', BulkTankMovementKind::Opening->value)->get();

    check($openings->count() === 2, '...two tanks were opened, each with one movement of its own kind', (string) $openings->count());

    $yard = BulkTank::query()->where('name', 'OPENVERIFY Yard Tank')->firstOrFail();
    $border = BulkTank::query()->where('name', 'OPENVERIFY Border Tank')->firstOrFail();

    check((int) $yard->stock_litres_x100 === 2_150_075 && (int) $yard->stock_value_base === 12_840_000
        && (int) $border->stock_litres_x100 === 820_000 && (int) $border->stock_value_base === 4_920_000,
        '...the book holds the litres and the value the dip sheet carried, scaled in integers',
        $yard->stock_litres_x100.'/'.$yard->stock_value_base.' and '.$border->stock_litres_x100.'/'.$border->stock_value_base);

    check((int) $yard->average_cost_per_litre_minor === 597
        && (int) $border->average_cost_per_litre_minor === 600,
        '...and each tank\'s moving average is value over litres, so the first issue after cutover is priced from the opening fill',
        (string) $yard->average_cost_per_litre_minor.' / '.(string) $border->average_cost_per_litre_minor);

    $stockNet = 0;
    $equityNet = 0;

    $stockRows = DB::table('gl_journal_lines')
        ->join('gl_journal_entries', 'gl_journal_entries.id', '=', 'gl_journal_lines.entry_id')
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.account_id')
        ->whereIn('gl_journal_entries.source_type', ['bulk_tank_opening'])
        ->selectRaw('gl_accounts.code as code, sum(gl_journal_lines.amount_base) as total')
        ->groupBy('gl_accounts.code')
        ->get();

    foreach ($stockRows as $row) {
        if ($row->code === '1180') {
            $stockNet = (int) $row->total;
        }

        if ($row->code === '3010') {
            $equityNet = (int) $row->total;
        }
    }

    check($stockNet === 12_840_000 + 4_920_000 && $equityNet === $stockNet,
        '...and the ledger carries the same value the tanks do: Dr 1180 against Cr 3010, once each',
        $stockNet.' / '.$equityNet);

    $tankRetry = $import->handle(CutoverDataset::OpeningTankStock, $tankFile, 'verify-opening-tank-retry.csv', $owner->id);
    $apply->handle($tankRetry, $owner->id);

    check(BulkTank::query()->where('name', 'OPENVERIFY Yard Tank')->value('stock_litres_x100') === 2_150_075,
        '...a retried tank file cannot fill the same tank twice (a book has one opening)',
        (string) BulkTank::query()->where('name', 'OPENVERIFY Yard Tank')->value('stock_litres_x100'));

    $badTank = $import->handle(
        CutoverDataset::OpeningTankStock,
        "tank_name,litres,value,read_on,description\nOPENVERIFY Yard Tank,40000.00,100.00,2026-10-31,over capacity\nOPENNOWHERE Tank,100.00,50.00,2026-10-31,\nOPENVERIFY Border Tank,500.00,0.00,2026-10-31,\n",
        'verify-opening-tank-bad.csv',
        $owner->id,
    );

    check($badTank->status === CutoverBatchStatus::Rejected, 'the bad tank file is refused');
    check(str_contains(rowErrors($badTank, 2), 'will not fit'), '...an over-capacity opening is a counting error, not a warning', rowErrors($badTank, 2));
    check(str_contains(rowErrors($badTank, 3), 'is not on the register'), '...a tank that was never opened has nothing to fill', rowErrors($badTank, 3));
    check(str_contains(rowErrors($badTank, 4), 'greater than zero'), '...and litres with no value would price the next trip at nothing', rowErrors($badTank, 4));

    $badOdo = $import->handle(
        CutoverDataset::OpeningOdometerReadings,
        "registration_no,read_on,odometer_km\nB 9001 NOPE,2026-10-31,1000\nB 9001 OV1,2026-10-31,190000\nB 9001 OV1,2026-10-31,190100\n",
        'verify-opening-odometer-bad.csv',
        $owner->id,
    );

    check(str_contains(rowErrors($badOdo, 2), 'is not in the fleet'), 'a reading for an unknown truck is named, not dropped', rowErrors($badOdo, 2));
    check(str_contains(rowErrors($badOdo, 4), 'already carries a reading'), '...and one truck cannot be read twice on the same day in one file', rowErrors($badOdo, 4));

    $tbWithStock = "account_code,debit,credit,description\n1180,177600.00,,diesel on hand\n3010,,177600.00,opening equity\n";
    $tbStockBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $tbWithStock, 'verify-opening-tb-after-tank.csv', $owner->id);

    check($tbStockBatch->status === CutoverBatchStatus::Rejected
        && str_contains(rowErrors($tbStockBatch, 2), 'already opened by the Opening tank stock intake'),
        '...and the yard litres follow the rule: one account, one opener', rowErrors($tbStockBatch, 2));

    /* ------------------------------------------- H: the cash a driver still holds */
    echo "\n== H: the advance the old system paid and never closed ==\n";

    $driverIds = Driver::query()
        ->whereIn('employee_id', array_values($fixtureEmployeeIds))
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $advanceEntries = static fn (): int => DB::table('gl_journal_entries')
        ->where('source_type', 'cutover_advance')
        ->whereIn('source_id', array_map(static fn ($id): string => (string) $id, $driverIds))
        ->count();

    $advBatch = $import->handle(CutoverDataset::OpeningDriverAdvances, $demoAdvances, 'verify-opening-advances-demo.csv', $owner->id);

    check($advBatch->status === CutoverBatchStatus::Validated, 'the demo advances file validates', batchError($advBatch).' | '.rowErrors($advBatch, 2));
    check((int) $advBatch->valid_row_count === 4, '...four slips on two drivers, every one taken', (string) $advBatch->valid_row_count);
    check(count(array_filter(explode("\n", CutoverErrorReport::build($advBatch)))) === 1, '...with the error report carrying only its header (the DoD, still literally true)');

    // The preview and the error report read a money cell by the file's own column name (the
    // payload convention). A row that stores the figure under another key still applies correctly
    // and still prints an em-dash where the accountant expects 3 500.00 — which is exactly the defect
    // the browser pass found on the screen, so it is asserted here rather than re-found later.
    $advanceRow = $advBatch->rows()->where('line_number', 2)->first();
    $advancePayload = is_array($advanceRow?->payload ?? null) ? (array) $advanceRow->payload : [];

    check(($advancePayload['amount'] ?? null) === 350_000 && is_int($advancePayload['amount']),
        '...and the row\'s money is stored under `amount`, the column the preview formats as money',
        json_encode($advancePayload));

    $apply->handle($advBatch, $owner->id);

    check($advanceEntries() === 4, '...four entries on the ledger, one per slip', (string) $advanceEntries());

    $advanceLines = DB::table('gl_journal_lines')
        ->join('gl_journal_entries', 'gl_journal_entries.id', '=', 'gl_journal_lines.entry_id')
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.account_id')
        ->where('gl_journal_entries.source_type', 'cutover_advance')
        ->whereIn('gl_journal_entries.source_id', array_map(static fn ($id): string => (string) $id, $driverIds))
        ->select('gl_accounts.code as code', 'gl_journal_lines.direction as direction', 'gl_journal_lines.amount_base as amount', 'gl_journal_lines.driver_id as driver_id')
        ->get();

    $debit1160 = $advanceLines->where('code', '1160')->where('direction', 'debit')->sum('amount');
    $credit3010 = $advanceLines->where('code', '3010')->where('direction', 'credit')->sum('amount');

    check((int) $debit1160 === 1_675_000 && (int) $credit3010 === 1_675_000,
        '...Dr 1160 and Cr 3010 both carry the file\'s total, 16 750.00: the cash left the old system\'s bank, so the other side is equity (the rule, not a new one)',
        $debit1160.' / '.$credit3010);

    check($advanceLines->where('code', '1160')->every(static fn ($line): bool => $line->driver_id !== null),
        '...and every receivable line names its driver — the account would have refused the entry without the dimension');

    $today = Carbon::now()->toDateString();
    $postedOnDays = DB::table('gl_journal_entries')->where('source_type', 'cutover_advance')->whereIn('source_id', array_map(static fn ($id): string => (string) $id, $driverIds))->pluck('posted_on')->unique()->all();

    check($postedOnDays === [$today],
        '...struck today, not on the day of the slip: an opening balance is a fact about cutover day, and the August float must not reach into a closed period (the decision 2)',
        implode(',', array_map(static fn ($d): string => (string) $d, $postedOnDays)));

    $described = DB::table('gl_journal_entries')->where('source_type', 'cutover_advance')->whereIn('source_id', array_map(static fn ($id): string => (string) $id, $driverIds))->pluck('description')->all();

    check($described !== [] && count(array_filter($described, static fn (string $d): bool => str_contains($d, 'paid 2026-08-02'))) === 1,
        '...and the slip\'s own day is still on the paper, in the description — the entry is dated today, the fact is not',
        implode(' / ', $described));

    $advRetry = $import->handle(CutoverDataset::OpeningDriverAdvances, $demoAdvances, 'verify-opening-advances-retry.csv', $owner->id);
    $apply->handle($advRetry, $owner->id);

    check($advanceEntries() === 4, '...and applying the same slips twice puts no cash on the books twice (the replay key doing a document row\'s work)', (string) $advanceEntries());

    $tbWithAdvances = "account_code,debit,credit,description\n1160,16750.00,,driver float\n3010,,16750.00,opening equity\n";
    $tbAdvanceBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $tbWithAdvances, 'verify-opening-tb-after-advances.csv', $owner->id);

    check($tbAdvanceBatch->status === CutoverBatchStatus::Rejected
        && str_contains(rowErrors($tbAdvanceBatch, 2), 'already opened by the Unreconciled driver advances intake'),
        '...the trial balance cannot also carry 1160 once the file did — one account, one opener, now four accounts deep',
        rowErrors($tbAdvanceBatch, 2));

    $badAdvances = $import->handle(
        CutoverDataset::OpeningDriverAdvances,
        implode("\n", [
            'employee_no,reference,amount,advanced_on,note',
            'OPENEMP-NOPE,ADV-B1,100.00,2026-10-01,',
            'OPENEMP-3,ADV-B2,100.00,2026-10-01,an employee who never drove',
            'OPENEMP-1,ADV-B3,-5.00,2026-10-01,',
            'OPENEMP-1,,100.00,2026-10-01,',
            'OPENEMP-2,ADV-B5,100.00,,',
            'OPENEMP-1,ADV-B6,100.00,2026-10-01,',
            'OPENEMP-1,ADV-B6,200.00,2026-10-02,',
        ]),
        'verify-opening-advances-bad.csv',
        $owner->id,
    );

    check($badAdvances->status === CutoverBatchStatus::Rejected, 'the bad advances file is rejected, not half-applied');
    check(str_contains(rowErrors($badAdvances, 2), 'not on the register'), '...an employee number the employees file has not imported is named', rowErrors($badAdvances, 2));
    check(str_contains(rowErrors($badAdvances, 3), 'no driver record'), '...and so is a payroll number with no driver behind it — 1160 asks for one', rowErrors($badAdvances, 3));
    check(str_contains(rowErrors($badAdvances, 4), 'positive amount'), '...money the driver handed back is a settlement, not a negative advance — the money parser refuses it in its own words', rowErrors($badAdvances, 4));
    check(str_contains(rowErrors($badAdvances, 5), 'Legacy slip number'), '...a row with no natural key is refused before it can become an un-findable entry', rowErrors($badAdvances, 5));
    check(str_contains(rowErrors($badAdvances, 6), 'Paid on is required'), '...and so is a row with no day', rowErrors($badAdvances, 6));
    check(str_contains(rowErrors($badAdvances, 8), 'already carries'), '...the same slip cannot appear twice in one file', rowErrors($badAdvances, 8));

    check($advanceEntries() === 4, '...none of which reached the ledger: the four entries are still the only ones', (string) $advanceEntries());

    /* --------------------------------------------------- I: the financing book */
    echo "\n== I: the facility this book inherited, and the plan it says nothing about ==\n";

    app(NumberSequenceRegistry::class)->ensureAll();

    $entriesBefore = DB::table('gl_journal_entries')->count();

    $loanBatch = $import->handle(CutoverDataset::OpeningLoans, $demoLoans, 'verify-opening-loans-demo.csv', $owner->id);

    check($loanBatch->status === CutoverBatchStatus::Validated, 'the demo loans file validates', batchError($loanBatch).' | '.rowErrors($loanBatch, 2));
    check((int) $loanBatch->valid_row_count === 3, '...three facilities, every one taken', (string) $loanBatch->valid_row_count);

    $loanRow = $loanBatch->rows()->where('line_number', 2)->first();
    $loanPayload = is_array($loanRow?->payload ?? null) ? (array) $loanRow->payload : [];

    check(($loanPayload['principal'] ?? null) === 48_600_000 && is_int($loanPayload['principal']),
        '...and the facility\'s principal sits under `principal`, the column the preview formats as money',
        json_encode($loanPayload));

    $apply->handle($loanBatch, $owner->id);

    $loans = Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->orderBy('id')->get();

    check($loans->count() === 3, '...three loans on the financing book', (string) $loans->count());
    check($loans->every(static fn (Loan $loan): bool => $loan->status === LoanLifecycle::Disbursed
        && $loan->disbursement_entry_id === null
        && $loan->disbursed_on === null),
        '...each `disbursed` and none with a disbursement: the drawdown is the old system\'s money, and this book has never seen a payment on it',
        $loans->map(static fn (Loan $loan): string => $loan->status->value.'/'.(string) $loan->disbursement_entry_id)->implode(' '));

    check($loans->every(static fn (Loan $loan): bool => $loan->currency_code === $base
        && $loan->fx_rate_source === 'opening_balance'
        && (float) $loan->fx_rate === 1.0),
        '...base currency only, at the rate the intake means: 1 unit of the base currency is 1 unit (the wall)');

    check(DB::table('gl_journal_entries')->count() === $entriesBefore,
        '...and the file posted nothing. Not one entry — the liability is the trial balance\'s 2210 line and always was (the decision 1)',
        $entriesBefore.' → '.(string) DB::table('gl_journal_entries')->count());

    $exactPlans = 0;
    $lastRowResidues = 0;
    $datedRight = 0;

    foreach ($loans as $loan) {
        $rows = LoanInstallment::query()->where('loan_id', $loan->id)->orderBy('seq')->get();

        if ((int) $rows->sum('principal_due') === (int) $loan->principal && $rows->count() === $loan->repayment_count) {
            $exactPlans++;
        }

        // The residue rule at origination: the division remainder is on the final row, so the plan
        // sums to the principal exactly rather than to the principal minus a few cents.
        $last = $rows->last();
        $first = $rows->first();

        if ($last !== null && (int) $last->principal_due === (int) $loan->principal - (int) $rows->slice(0, -1)->sum('principal_due')) {
            $lastRowResidues++;
        }

        if ($first !== null && $first->due_on->toDateString() === $loan->first_payment_on->toDateString()) {
            $expectedSecond = $loan->frequency === LoanFrequency::Fortnightly
                ? $first->due_on->copy()->addDays(14)->toDateString()
                : $first->due_on->copy()->addMonth()->toDateString();

            if ($rows->count() < 2 || $rows[1]->due_on->toDateString() === $expectedSecond) {
                $datedRight++;
            }
        }
    }

    check($exactPlans === 3, '...each plan sums to the principal still owing, over exactly the rows still to come (the design stores the schedule; Rebuilds it)', (string) $exactPlans);
    check($lastRowResidues === 3, '...and the residue lands on the last row, so no plan disagrees with its facility by a cent', (string) $lastRowResidues);
    check($datedRight === 3, '...spaced from the next due date the file named, monthly or fortnightly', (string) $datedRight);

    $abSa = $loans->first(static fn (Loan $loan): bool => $loan->principal === 48_600_000);

    check($abSa !== null && $abSa->outstandingMinor() === 48_600_000 + (int) LoanInstallment::query()->where('loan_id', $abSa->id)->sum('interest_due'),
        '...the 486 000.00 facility reads as its plan: principal plus the interest the frozen rows charge (the design has one referee, and it is not a spreadsheet)',
        $abSa === null ? 'row missing' : (string) $abSa->outstandingMinor());

    check($abSa !== null && $abSa->installments()->where('seq', 1)->value('interest_due') === 597_375,
        '...14.75 % a year on the outstanding balance charges 5 973.75 on the first row, floor-rounded per period',
        $abSa === null ? 'row missing' : (string) $abSa->installments()->where('seq', 1)->value('interest_due'));

    check($loans->every(static fn (Loan $loan): bool => $loan->status->isOwed()),
        '...and every one of them is in the 13-week forecast\'s status list — the whole reason this file exists',
        $loans->map(static fn (Loan $loan): string => $loan->status->value)->implode('/'));

    $loanRetry = $import->handle(CutoverDataset::OpeningLoans, $demoLoans, 'verify-opening-loans-retry.csv', $owner->id);
    $apply->handle($loanRetry, $owner->id);

    check(Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->count() === 3,
        '...a retried file raises no second facility for the same truck (the natural key, and the lookup runs before anything that could refuse a retry — the lesson)',
        (string) Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->count());
    check(LoanInstallment::query()->whereIn('loan_id', $loans->pluck('id')->all())->count() === 45,
        '...nor a second set of instalment rows: 36 + 8 + 1, still the only plan the forecast walks',
        (string) LoanInstallment::query()->whereIn('loan_id', $loans->pluck('id')->all())->count());

    // The ordering claim in the picker's own sentence: a loan file owns no account, so the trial
    // balance is still free to carry 2210 afterwards — no double count, which is why this one is a
    // schedule rather than a posting.
    $tbWithLoan = "account_code,debit,credit,description\n2210,663750.55,,finance loans\n3010,,663750.55,opening equity\n";
    $tbLoanBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $tbWithLoan, 'verify-opening-tb-after-loans.csv', $owner->id);

    check($tbLoanBatch->status === CutoverBatchStatus::Validated,
        '...and a trial balance may still carry 2210 after the facilities arrive: the plan owns no account, so nothing is counted twice',
        batchError($tbLoanBatch).' | '.rowErrors($tbLoanBatch, 2));

    $badLoans = $import->handle(
        CutoverDataset::OpeningLoans,
        implode("\n", [
            'reference,principal,annual_rate_percent,frequency,repayment_count,first_payment_on,purpose,truck_registration_no,supplier_code,bank_account_name,notes',
            'L-B1,1000.00,12.5,weekly,12,2026-12-01,,,,,',
            'L-B2,1000.00,1275,monthly,12,2026-12-01,,,,,',
            'L-B3,1000.00,12.5,monthly,0,2026-12-01,,,,,',
            'L-B4,1000.00,12.5,monthly,12,2026-12-01,vehicle,B 9001 NOPE,,,',
            'L-B5,1000.00,12.5,monthly,12,2026-12-01,working_capital,B 9001 OV1,,,',
            'L-B6,1000.00,12.5,monthly,12,2026-12-01,vehicle,,OPENSUP-NOPE,,',
            'L-B7,1000.00,12.5,monthly,12,2026-12-01,,,,NOPE Account,',
            'L-B8,0.00,12.5,monthly,12,2026-12-01,,,,,',
            'L-B9,1000.00,12.5,monthly,12,not-a-date,,,,,',
            'L-B7,2000.00,12.5,monthly,6,2026-12-01,,,,,',
        ]),
        'verify-opening-loans-bad.csv',
        $owner->id,
    );

    check($badLoans->status === CutoverBatchStatus::Rejected, 'the bad loans file is rejected');
    check(str_contains(rowErrors($badLoans, 2), 'neither monthly nor fortnightly'), '...a weekly plan is refused by name, because spaces the rows and cannot guess', rowErrors($badLoans, 2));
    check(str_contains(rowErrors($badLoans, 3), 'not a fraction'), '...a rate typed as a fraction is caught before it silently charges 1275 % a year', rowErrors($badLoans, 3));
    check(str_contains(rowErrors($badLoans, 4), 'at least 1'), '...a facility with no rows left is closed, not opening', rowErrors($badLoans, 4));
    check(str_contains(rowErrors($badLoans, 5), 'is not in the fleet'), '...a truck the trucks file has not imported is named', rowErrors($badLoans, 5));
    check(str_contains(rowErrors($badLoans, 6), 'names no truck'), '...and a working-capital facility cannot name one either', rowErrors($badLoans, 6));
    check(str_contains(rowErrors($badLoans, 7), 'not in the register'), '...a lender that is not a supplier is named', rowErrors($badLoans, 7));
    check(str_contains(rowErrors($badLoans, 8), 'not on the register'), '...and so is a bank account nobody opened', rowErrors($badLoans, 8));
    check(str_contains(rowErrors($badLoans, 9), 'greater than zero'), '...a zero balance is not a facility', rowErrors($badLoans, 9));
    check(str_contains(rowErrors($badLoans, 11), 'already carries facility'), '...one legacy loan number cannot appear twice in one file', rowErrors($badLoans, 11));

    check(Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->count() === 3, '...none of which reached the book: three facilities still', (string) Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->count());
} catch (Throwable $blew) {
    fail('the run stopped: '.get_class($blew), $blew->getMessage().' @ line '.$blew->getLine());
} finally {
    echo "\nCleanup\n-------\n";

    $sweep();

    $left = Invoice::query()->where('source_uuid', 'like', 'opening:OPENCUT-%')->count()
        + Bill::query()->where('source_uuid', 'like', 'opening-bill:OPENSUP-%')->count()
        + CutoverBatch::query()->where('original_filename', 'like', 'verify-opening-%')->count()
        + Customer::query()->where('code', 'like', 'OPENCUT-%')->count()
        + Supplier::query()->where('code', 'like', 'OPENSUP-%')->count()
        + OdometerReading::query()->where('source', OdometerSource::Cutover->value)->count()
        + BulkTankMovement::query()->where('kind', BulkTankMovementKind::Opening->value)->count()
        + BulkTank::query()->where('name', 'like', 'OPENVERIFY %')->count()
        + DB::table('gl_journal_entries')->where('source_type', 'cutover_advance')->count()
        + Loan::query()->where('source_uuid', 'like', 'opening-loan:%')->count()
        + LoanInstallment::query()->whereIn('loan_id', DB::table('loans')->where('source_uuid', 'like', 'opening-loan:%')->pluck('id')->all())->count()
        + Employee::query()->where('employee_no', 'like', 'OPENEMP-%')->count()
        + Driver::query()->whereIn('employee_id', DB::table('employees')->where('employee_no', 'like', 'OPENEMP-%')->pluck('id')->all())->count()
        + Truck::query()->where('registration_no', 'like', 'B 9001 OV%')->count();

    $left === 0 ? ok('no opening-intake rows left behind') : fail('leftover rows', (string) $left);
}

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — OPENING INTAKE OK' : ' — OPENING INTAKE FAILED')."\n";

exit($failures === 0 ? 0 : 1);
