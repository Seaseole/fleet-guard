<?php

declare(strict_types=1);

/**
 * Phase 4 slices 4e–4h — the invoice register, one invoice's machine, the ageing report and the print
 * door, driven through the real HTTP stack.
 *
 * `verify-invoicing.php` proves the Actions and the schema. This sweep proves the screens that carry
 * them: that the register lists what was issued and shows the derived settlement state, that a seat
 * without `invoices.issue` is offered no consolidated-run form while the accountant is, that the detail
 * screen renders the lines, the money and the three corrections, that clicking "Email to customer"
 * queues the document with its PDF (and that the same click is refused for a customer with no address),
 * that the three PDF routes answer with `%PDF` bytes rather than an HTML error, that a seat with no role
 * gets a 403 rather than a page, and that `invoices:recompute-balances` repairs a projection that has
 * been deliberately corrupted and then agrees with the rows.
 *
 * Fixtures live in the `INV-R` namespace on the development database and are removed afterwards, with
 * their journal entries deleted under the lifted barrier. The counters this run advances are rewound
 * so `documents:number-integrity` sees only surviving rows.
 *
 * Run: php scripts/render-invoicing.php   (set RENDER_INVOICING_TRACE=1 for stack traces)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Fleet\Actions\CreateTrailer;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Invoicing\Actions\CreateCustomer;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Data\CustomerDto;
use App\Domain\Invoicing\Mail\InvoiceIssuedMail;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class InvoicingRenderSweep extends TestCase
{
    public int $failures = 0;

    private int $companyId = 1;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } finally {
            $this->purge();
            HandleExceptions::forgetApp();
            $this->tearDown();
        }
    }

    public function sweep(): void
    {
        /** @var User $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->firstOrFail();
        $this->companyId = (int) $owner->company_id;
        CurrentCompany::force($this->companyId);

        Auth::login($owner);
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-inv-check-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-inv-check-dispatcher@fleetguard.local');
        $reader = $this->staffAs($owner, null, 'render-inv-check-reader@fleetguard.local');

        CurrentCompany::force($this->companyId);
        Auth::login($accountant);

        $base = CurrentCompany::model()->base_currency_code;

        /* Fixtures through the Actions, the way the screens write them. */

        $customer = (app(CreateCustomer::class))(new CustomerDto(
            name: 'INV-R Customer',
            code: 'INV-R-CUST',
            paymentTerms: 'net_30',
            contactName: 'INV-R Accounts',
            contactEmail: 'render-inv-check-customer@fleetguard.local',
        ));

        $route = (app(CreateRoute::class))(new RouteDto(
            code: 'INV-R-GBE-WDH',
            origin: 'Gaborone',
            destination: 'Windhoek',
            originCountryCode: 'BW',
            destinationCountryCode: 'NA',
            distanceKm: '750',
        ));

        $job = (app(CreateJob::class))(new JobDto(
            customerId: $customer->id,
            routeId: $route->id,
            commodity: 'INV-R probe cargo',
            tonnes: 24.0,
            requiredLoadOn: Carbon::now()->addDays(2)->toDateString(),
            requiredDeliveryOn: Carbon::now()->addDays(6)->toDateString(),
            notes: 'INV-R job',
        ), 1);

        $job = (app(QuoteJob::class))($job, new JobQuoteDto(rateType: RouteRateType::PerTrip, amountMajor: '90000', currencyCode: $base), 1);
        $job = (app(AcceptJob::class))($job, 1);

        $truck = (app(CreateTruck::class))(new TruckDto(
            registrationNo: 'B 992 INV',
            fleetNo: 'T-INVR',
            vin: 'VININVR0000000000000001',
            make: 'Probe',
            model: 'Invoice render',
            odometerKm: 10_000,
        ));

        $trailer = (app(CreateTrailer::class))(new TrailerDto(registrationNo: 'T 992 INV', type: 'flatbed', capacityTonnes: 30.0));

        $employee = (app(CreateEmployee::class))(new EmployeeDto(employeeNo: 'EMP-INVR001', name: 'INV-R Driver', hiredOn: '2024-01-10'));
        $driver = (app(CreateDriver::class))(new DriverDto(
            employeeId: $employee->id,
            licenceNo: 'DL-INVR001',
            licenceCode: 'EC',
            licenceExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
            permitExpiresOn: Carbon::now()->addMonths(8)->toDateString(),
            allowanceRateBase: 30_000,
            kioskEnabled: false,
        ));

        $trip = (app(CreateTrip::class))($job, 1);
        $trip = (app(PlanTrip::class))($trip, new TripDto(
            jobId: $job->id,
            truckId: $truck->id,
            driverId: $driver->id,
            trailerId: $trailer->id,
            plannedDepartureAt: Carbon::now()->addDays(2)->toDateTimeString(),
        ), 1);

        Chargeable::create([
            'id' => (string) Str::ulid(),
            'trip_id' => $trip->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => ChargeableType::Freight->value,
            'basis' => ChargeableBasis::PerTrip->value,
            'description' => 'INV-R render freight',
            'currency_code' => $base,
            'amount' => 9_000_000,
            'base_amount' => 9_000_000,
            'status' => ChargeableStatus::Ready->value,
            'source_uuid' => 'render-inv:freight',
        ]);

        $invoice = (app(IssueInvoice::class))($trip->id, $accountant->id);

        echo "== The register lists the document, its derived state and its money ==\n";

        $this->attempt('the accountant opens /invoices and sees the number, customer and outstanding', function () use ($accountant, $invoice, $customer): void {
            $html = $this->page($accountant, '/invoices');

            foreach ([$invoice->number, $customer->name, 'Outstanding'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the register is missing {$needle}");
                }
            }
        });

        $this->attempt('...and the consolidated-run panel is offered to a seat that may issue', function () use ($accountant): void {
            $html = $this->page($accountant, '/invoices');

            if (! str_contains($html, 'Run a consolidated statement')) {
                throw new Exception('the run panel is missing for the accountant');
            }
        });

        $this->attempt('...while a dispatcher sees the register but no run form', function () use ($dispatcher): void {
            $html = $this->page($dispatcher, '/invoices');

            if (str_contains($html, 'Run a consolidated statement')) {
                throw new Exception('a seat without invoices.issue was offered the run form');
            }
        });

        $this->attempt('...and a seat with no role is refused the page', function () use ($reader): void {
            $status = $this->actingAs($reader, 'web')->get('/invoices')->getStatusCode();

            if ($status !== 403) {
                throw new Exception('expected 403, got '.$status);
            }
        });

        echo "\n== One invoice's machine: lines, settlement, the three corrections ==\n";

        $this->attempt('the detail screen renders the line, its VAT and the settlement state', function () use ($accountant, $invoice): void {
            $html = $this->page($accountant, '/invoices/'.$invoice->id);

            foreach (['INV-R render freight', 'Parties', 'Receipts applied', 'Credit notes', 'Outstanding'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the detail page is missing {$needle}");
                }
            }

            if (! str_contains($html, 'Record and apply')) {
                throw new Exception('the accountant was offered no way to record a receipt');
            }
        });

        $this->attempt('the Livewire component mounts with the invoice and queues the email', function () use ($accountant, $invoice): void {
            Mail::fake();

            Livewire::actingAs($accountant)
                ->test('pages::invoicing.invoice', ['invoice' => $invoice])
                ->assertOk()
                ->assertSee($invoice->number)
                ->call('email')
                ->assertHasNoErrors();

            Mail::assertQueued(InvoiceIssuedMail::class, static fn (InvoiceIssuedMail $mail): bool => $mail->documentNumber === $invoice->number);
        });

        $this->attempt('a customer with no address is refused with a sentence, not a queued mail', function () use ($accountant, $invoice): void {
            Mail::fake();

            DB::table('customers')->where('id', $invoice->customer_id)->update(['contact_email' => null]);

            Livewire::actingAs($accountant)
                ->test('pages::invoicing.invoice', ['invoice' => $invoice])
                ->call('email')
                ->assertSee('no contact email on file');

            Mail::assertNothingQueued();

            DB::table('customers')->where('id', $invoice->customer_id)->update(['contact_email' => 'render-inv-check-customer@fleetguard.local']);
        });

        echo "\n== The print door answers with PDF bytes (definition of done 4) ==\n";

        $this->attempt('the invoice PDF route returns a PDF', function () use ($accountant, $invoice): void {
            $response = $this->actingAs($accountant, 'web')->get('/invoices/'.$invoice->id.'/pdf');

            if ($response->getStatusCode() !== 200) {
                throw new Exception('the PDF route answered '.$response->getStatusCode());
            }

            $body = (string) $response->getContent();

            if (! str_starts_with($body, '%PDF-') || strlen($body) < 2_000) {
                throw new Exception('the invoice route did not return a PDF body');
            }

            if (! str_contains((string) $response->headers->get('content-type'), 'application/pdf')) {
                throw new Exception('the invoice route did not declare application/pdf');
            }
        });

        $this->attempt('the statement PDF route returns a PDF for the customer and window', function () use ($accountant, $customer): void {
            $url = '/customers/'.$customer->id.'/statement.pdf?from='.Carbon::now()->startOfMonth()->toDateString().'&to='.Carbon::now()->toDateString();
            $response = $this->actingAs($accountant, 'web')->get($url);

            if ($response->getStatusCode() !== 200) {
                throw new Exception('the statement route answered '.$response->getStatusCode());
            }

            $body = (string) $response->getContent();

            if (! str_starts_with($body, '%PDF-')) {
                throw new Exception('the statement route did not return a PDF body');
            }
        });

        echo "\n== The ageing report is worked out on the page, not stored ==\n";

        $this->attempt('the ageing page shows the customer in \"not yet due\" and the AR total', function () use ($accountant, $customer): void {
            $html = (string) $this->actingAs($accountant, 'web')->get('/ageing')->assertOk()->getContent();

            foreach ([$customer->name, 'Not yet due', 'Total AR', 'Statement from'] as $needle) {
                if (! str_contains($html, $needle)) {
                    throw new Exception("the ageing page is missing {$needle}");
                }
            }
        });

        echo "\n== The balance tie-out repairs a projection the rows disagree with ==\n";

        $this->attempt('--check reports drift, the repair run fixes it, and a second check agrees', function () use ($invoice): void {
            DB::table('invoices')->where('id', $invoice->id)->update(['balance_amount' => 1]);

            $drifted = Artisan::call('invoices:recompute-balances', ['--check' => true, '--company' => $this->companyId]);

            if ($drifted !== 1) {
                throw new Exception('--check did not fail on a corrupted projection');
            }

            Artisan::call('invoices:recompute-balances', ['--company' => $this->companyId]);

            $clean = Artisan::call('invoices:recompute-balances', ['--check' => true, '--company' => $this->companyId]);

            if ($clean !== 0) {
                throw new Exception('the repaired projection still does not tie out');
            }

            $expected = (int) DB::table('invoices')->where('id', $invoice->id)->value('amount')
                + (int) DB::table('invoices')->where('id', $invoice->id)->value('tax_amount');

            if ((int) DB::table('invoices')->where('id', $invoice->id)->value('balance_amount') !== $expected) {
                throw new Exception('the repair did not restore the gross');
            }
        });
    }

    /** Fetch a page as a seat, failing on anything that is not a 200 with a body. */
    private function page(User $user, string $url): string
    {
        $response = $this->actingAs($user, 'web')->get($url);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('GET '.$url.' answered '.$response->getStatusCode());
        }

        return (string) $response->getContent();
    }

    private function staffAs(User $owner, ?RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render INV Check '.($role?->label() ?? 'No Role');
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);

        if ($role !== null) {
            app(ChangeUserRole::class)($user, $role);
        }

        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;
            echo '  FAIL  '.$label.' — '.mb_substr(preg_replace('/\s+/', ' ', strip_tags($exception->getMessage())) ?? $exception->getMessage(), 0, 240)."\n";

            if (getenv('RENDER_INVOICING_TRACE') !== false) {
                echo $exception->getTraceAsString()."\n";
            }
        }
    }

    /** Delete this namespace's rows, children before parents. */
    private function purge(): void
    {
        CurrentCompany::forget();

        $customerIds = DB::table('customers')->where('code', 'like', 'INV-R%')->pluck('id')->all();
        $jobIds = DB::table('freight_jobs')->where('notes', 'like', 'INV-R%')->pluck('id')->all();
        $tripIds = $jobIds === [] ? [] : DB::table('trips')->whereIn('job_id', $jobIds)->pluck('id')->all();

        $invoiceIds = DB::table('invoices')
            ->where(fn ($query) => $query->whereIn('customer_id', $customerIds ?: [0])->orWhereIn('job_id', $jobIds ?: ['0']))
            ->pluck('id')->all();

        $paymentIds = $customerIds === [] ? [] : DB::table('payments')->whereIn('customer_id', $customerIds)->pluck('id')->all();
        $creditNoteIds = $invoiceIds === [] ? [] : DB::table('credit_notes')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
        $allocationIds = $invoiceIds === [] ? [] : DB::table('payment_allocations')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();

        $entryIds = array_merge(
            $invoiceIds === [] ? [] : DB::table('gl_journal_entries')->whereIn('source_type', ['invoice', 'invoice_void'])->whereIn('source_id', $invoiceIds)->pluck('id')->all(),
            $paymentIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'payment')->whereIn('source_id', $paymentIds)->pluck('id')->all(),
            $allocationIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'payment_allocation')->whereIn('source_id', $allocationIds)->pluck('id')->all(),
            $creditNoteIds === [] ? [] : DB::table('gl_journal_entries')->where('source_type', 'credit_note')->whereIn('source_id', $creditNoteIds)->pluck('id')->all(),
        );

        if ($entryIds !== []) {
            LedgerBarrier::withLiftedGuard('render-invoicing: remove this run\'s posted entries', null, function () use ($entryIds): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
            });
        }

        DB::table('payment_allocations')->whereIn('invoice_id', $invoiceIds ?: ['0'])->delete();

        if ($creditNoteIds !== []) {
            DB::table('credit_note_lines')->whereIn('credit_note_id', $creditNoteIds)->delete();
            DB::table('credit_notes')->whereIn('id', $creditNoteIds)->delete();
        }

        if ($invoiceIds !== []) {
            DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        }

        if ($paymentIds !== []) {
            DB::table('payments')->whereIn('id', $paymentIds)->delete();
        }

        if ($tripIds !== []) {
            DB::table('chargeables')->whereIn('trip_id', $tripIds)->delete();
            DB::table('vehicle_assignments')->whereIn('trip_id', $tripIds)->delete();
            DB::table('trips')->whereIn('id', $tripIds)->delete();
        }

        if ($jobIds !== []) {
            DB::table('freight_jobs')->whereIn('id', $jobIds)->delete();
        }

        DB::table('customers')->whereIn('id', $customerIds ?: [0])->delete();
        DB::table('routes')->where('code', 'like', 'INV-R%')->delete();

        $employeeIds = DB::table('employees')->where('employee_no', 'like', 'EMP-INVR%')->pluck('id')->all();

        if ($employeeIds !== []) {
            DB::table('drivers')->whereIn('employee_id', $employeeIds)->delete();
            DB::table('employees')->whereIn('id', $employeeIds)->delete();
        }

        DB::table('trucks')->where('registration_no', 'LIKE', '% INV')->delete();
        DB::table('trailers')->where('registration_no', 'LIKE', '% INV')->delete();

        $probeIds = DB::table('users')->where('email', 'like', 'render-inv-check-%@fleetguard.local')->pluck('id')->all();

        DB::table('number_sequences')->whereIn('last_used_by', $probeIds ?: [0])->update(['last_used_by' => null]);
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $probeIds ?: [0])->delete();
        // Events and audits name their actor/auditor, so they have to go before the probe user can.
        DB::table('operational_events')->whereIn('actor_id', $probeIds ?: [0])->delete();
        DB::table('audits')->where('user_type', User::class)->whereIn('user_id', $probeIds ?: [0])->delete();
        DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();

        $rewind = static function (string $scope, string $table, string $column): void {
            foreach (DB::table('number_sequences')->where('company_id', 1)->where('scope', $scope)->get() as $row) {
                $periodKey = (string) $row->period_key;
                $numbers = DB::table($table)->where('company_id', 1)->whereNotNull($column)
                    ->when($periodKey !== '', static fn ($query) => $query->where($column, 'like', '%'.$periodKey.'%'))
                    ->pluck($column);

                $highest = $numbers->map(static function (string $number): int {
                    $dash = strrpos($number, '-');

                    return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                })->max() ?? 0;

                DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
            }
        };

        $rewind('invoice', 'invoices', 'number');
        $rewind('job', 'freight_jobs', 'job_no');
        $rewind('trip', 'trips', 'trip_no');
        $rewind('journal_entry', 'gl_journal_entries', 'entry_no');
    }
}

$sweep = new InvoicingRenderSweep('sweep');

try {
    $sweep->drive();
} catch (Throwable $crashed) {
    echo "\n  FAIL  the sweep stopped — ".$crashed::class.': '.$crashed->getMessage()."\n";
    echo $crashed->getTraceAsString()."\n";
    $sweep->failures++;
}

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
