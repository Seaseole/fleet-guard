<?php

declare(strict_types=1);

/**
 * Phase 5 slice 5a — the bills register and the bill detail, driven through the real HTTP/Livewire stack.
 *
 * `verify-bills.php` proves the door: the posting, the dimensions, the refusals. It cannot prove the two
 * things these screens exist for — that a clerk's paper document actually becomes that posting when the
 * form is pressed, and that the account numbers and the leg reach the page a person reads before paying.
 * A door that is correct in a script and unusable on the screen is a paper still sitting in a drawer.
 *
 * The other half of what this file watches is who may not: a Dispatcher without `accounting.view` is
 * refused the register outright (§9.1), and the record card is rendered only for the seat that holds
 * `bills.create` (§9.2 — the Action asks again, and verify-bills proves that half).
 *
 * Not a test suite: `tests/` stays out of the repository (§14.6). Fixtures live in the `RENDER-BILL`
 * namespace on the development database; everything created is removed and then asserted removed,
 * journals through the ledger's own hatch, and every counter this run could advance is rewound (§20.8).
 *
 * Run: php scripts/render-bills.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Accounting\Actions\CreateSupplier;
use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Data\SupplierDto;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\BillLine;
use App\Domain\Accounting\Models\SupplierPayment;
use App\Domain\Fleet\Actions\CreateTruck;
use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\FuelType;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Fuel\Actions\CreateBulkTank;
use App\Domain\Fuel\Actions\CreateFuelPurchase;
use App\Domain\Fuel\Data\FuelPurchaseDto;
use App\Domain\Fuel\Queries\UnbilledDeliveries;
use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @extends TestCase
 */
final class BillsRenderSweep extends TestCase
{
    public int $failures = 0;

    private string $lastBillId = '';

    private ?int $ownerCompany = null;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function sweep(): void
    {
        /** @var User|null $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->first();

        if ($owner === null) {
            $this->note('No Owner row for '.config('fleet.identity.owner_email').' — run php artisan fleet:setup-owner (§9.1).');

            return;
        }

        $this->ownerCompany = $owner->company_id;
        CurrentCompany::force($owner->company_id);

        // §9.1's MFA gate is asserted where it belongs (render-identity); this sweep is about the
        // bills screens' authorisation and rendering, so that one middleware steps aside here.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);

        foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse(Carbon::now()->toDateString())) as $created) {
            echo "  sequence row created: {$created}\n";
        }

        $accountant = $this->staffAs($owner, RoleKey::Accountant, 'render-bill-accountant@fleetguard.local');
        $dispatcher = $this->staffAs($owner, RoleKey::Dispatcher, 'render-bill-dispatcher@fleetguard.local');

        // staffAs() releases the context on its way out (§4.3 fail-closed); the fixtures below are
        // scoped reads and writes, so the company has to be re-established before them.
        CurrentCompany::force($owner->company_id);

        $supplier = app(CreateSupplier::class)(new SupplierDto(
            name: 'RENDER-BILL Garage',
            code: 'RENDER-BILL-SUP',
            paymentTerms: 'net_30',
        ));

        $truck = app(CreateTruck::class)(new TruckDto(
            registrationNo: 'B 993 RB1', fleetNo: 'T-RB1', vin: 'VINRB10000000000000001',
            make: 'Probe', model: 'Render bill', odometerKm: 10_000,
        ));

        echo "\nThe register renders for the seats that own it, and refuses the one that does not\n";
        $this->pageContains($accountant, '/accounting/bills', 'Record a supplier bill', 'the Accountant sees the record card');
        $this->pageContains($accountant, '/accounting/bills', 'Outstanding payable', '...and reads the register total');
        // §9.3: the Dispatcher is the seat that reads this app all day and holds no `accounting.view`.
        // (Every other seeded role does hold it, so there is no second refusal to prove, and the dev
        // box's owner row carries an unstable role state from a past phase's seat probe — the sweep
        // asserts through its own accounts rather than editing anyone's staff row.)
        $this->assertForbidden($dispatcher, '/accounting/bills', 'a Dispatcher without accounting.view is refused the screen');

        echo "\nThe form turns a paper document into the posting the door promises\n";

        $this->attempt('fuel without a leg is refused on the page, by name', function () use ($accountant, $supplier, $truck): void {
            Livewire::actingAs($accountant)
                ->test('pages::accounting.bills')
                ->set('supplierId', (string) $supplier->id)
                ->set('rows', [
                    ['description' => 'Diesel with no trip', 'category' => 'fuel', 'amount' => '500.00', 'truck' => (string) $truck->id, 'trip' => ''],
                ])
                ->call('record')
                ->assertSee('requires a trip');

            if (DB::table('bills')->where('supplier_id', $supplier->id)->exists()) {
                throw new Exception('the refusal still wrote a bill');
            }
        });

        $this->attempt('a three-line bill records, posts, and lands on its detail page', function () use ($accountant, $supplier, $truck): void {
            $tripId = DB::table('trips')->where('company_id', $this->ownerCompany)->orderByDesc('trip_no')->value('id');

            if ($tripId === null) {
                throw new Exception('no trip in the company for the fuel line to name — seed the fleet first');
            }

            Livewire::actingAs($accountant)
                ->test('pages::accounting.bills')
                ->set('supplierId', (string) $supplier->id)
                ->set('supplierDocNo', 'RB-2026-900')
                ->set('billOn', Carbon::now()->toDateString())
                ->set('dueOn', Carbon::now()->addDays(30)->toDateString())
                ->set('rows', [
                    ['description' => 'Diesel burned on the probe leg', 'category' => 'fuel', 'amount' => '500.00', 'truck' => (string) $truck->id, 'trip' => (string) $tripId],
                    ['description' => 'Clutch repair at the garage', 'category' => 'labour', 'amount' => '1250.50', 'truck' => (string) $truck->id, 'trip' => ''],
                    ['description' => 'Annual hull policy', 'category' => 'insurance', 'amount' => '900.00', 'truck' => '', 'trip' => ''],
                ])
                ->call('record')
                ->assertHasNoErrors()
                ->assertRedirect();

            $bill = DB::table('bills')->where('supplier_id', $supplier->id)->orderByDesc('created_at')->first();

            if ($bill === null) {
                throw new Exception('the pressed form wrote no bill');
            }

            $net = 50_000 + 125_050 + 90_000;
            $tax = 0;

            // The register's own percentage, not a hard-coded 12%: §20.37 made the standard rate a
            // runtime figure, so a sweep that assumes one is a sweep that fails the week it changes.
            $rateX = (int) (TaxRate::effectiveOn(Carbon::now())->rate_x10000 ?? 0);

            if ($rateX === 0) {
                throw new Exception('no standard VAT rate applies today, so the arithmetic below has no answer');
            }

            foreach ([50_000, 125_050, 90_000] as $line) {
                $tax += intdiv($line * $rateX + 500_000, 1_000_000);
            }

            if ((int) $bill->amount !== $net + $tax) {
                throw new Exception("the page recorded {$bill->amount}, expected ".($net + $tax).' (gross, VAT per line)');
            }

            if ((int) $bill->tax_amount !== $tax) {
                throw new Exception("VAT {$bill->tax_amount}, expected {$tax}");
            }

            $entry = DB::table('gl_journal_entries')->where('source_type', 'bill')->where('source_id', $bill->id)->first();

            if ($entry === null) {
                throw new Exception('the bill posted nothing to the ledger');
            }

            $lines = DB::table('gl_journal_lines')->where('entry_id', $entry->id)->get();
            $debit = (int) $lines->where('direction', 'debit')->sum('amount_base');
            $credit = (int) $lines->where('direction', 'credit')->sum('amount_base');

            if ($debit !== $credit || $credit !== $net + $tax) {
                throw new Exception("the entry does not balance at the gross: debit {$debit}, credit {$credit}");
            }

            $this->lastBillId = (string) $bill->id;
        });

        if ($this->lastBillId !== '') {
            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'What the ledger holds', 'the detail renders the ledger summary card');
            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, '2126', '...and prints the input VAT account the bill claimed');
            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'B 993 RB1', '...and the leg the fuel line named, not a bare id');

            echo "\nVoiding wants a reason, mirrors the posting, and stays on the record\n";

            $this->attempt('a blank reason is a field error, not a ledger write', function () use ($accountant): void {
                Livewire::actingAs($accountant)
                    ->test('pages::accounting.bill', [$this->lastBillId])
                    ->set('voidReason', '')
                    ->call('void')
                    ->assertHasErrors('voidReason');
            });

            $this->attempt('a stated reason mirrors the entry and releases the lines', function () use ($accountant): void {
                Livewire::actingAs($accountant)
                    ->test('pages::accounting.bill', [$this->lastBillId])
                    ->set('voidReason', 'Screen check: the mis-keyed policy')
                    ->call('void')
                    ->assertHasNoErrors();

                $bill = DB::table('bills')->where('id', $this->lastBillId)->first();

                if ((string) $bill->status !== BillLifecycle::Void->value) {
                    throw new Exception('the bill is '.$bill->status.' after the void');
                }

                $mirror = DB::table('gl_journal_entries')->where('source_type', 'bill_void')->where('source_id', $this->lastBillId)->first();

                if ($mirror === null) {
                    throw new Exception('the void wrote no mirror entry (§6.4)');
                }

                if (DB::table('bill_lines')->where('bill_id', $this->lastBillId)->whereNull('released_at')->exists()) {
                    throw new Exception('a line survived the void still unreleased');
                }
            });

            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'mis-keyed policy', 'the void reason is on the page');
        }

        echo "\nThe register lists what the page wrote\n";
        $this->pageContains($accountant, '/accounting/bills', 'RENDER-BILL Garage', 'the supplier names its row in the register');
        $this->pageContains($accountant, '/accounting/bills?state=void', 'BILL-', 'the state filter finds the voided document');
        $this->pageContains($accountant, '/accounting/bills', 'This paper is', 'and the panel asks which shape the next document takes (§20.36)');

        echo "\nSlice 5b: the paper for a delivery the ledger already owes, and the money that clears it\n";

        // Phase 3's own doors, driven by a seat that holds `fuel.record`: the obligation this next bill
        // documents has to be real, because the whole claim of the slice is that the paper adds no debit.
        $workshop = $this->staffAs($owner, RoleKey::Owner, 'render-bill-workshop@fleetguard.local');

        // The doors ask the authenticated seat, not an actor id, so the probe has to be logged in for
        // the fixture to be real (§9.2). Livewire's own actingAs covers the component tests below.
        Auth::login($workshop);
        CurrentCompany::force($owner->company_id);

        $obligationId = $this->attemptValue('a bulk delivery posts through Fuel\'s own door', function () use ($workshop): string {
            $currency = CurrentCompany::model()->base_currency_code;
            $tank = app(CreateBulkTank::class)('RENDER-BILL Yard tank', 4_000);

            $delivery = app(CreateFuelPurchase::class)(new FuelPurchaseDto(
                amount: Money::ofMajor('8000.00', $currency),
                rate: '1',
                rateSource: FxRateSource::Manual,
                rateDate: Carbon::now(),
                purchasedOn: Carbon::now(),
                litresX100: 400_000,
                pricePerLitreMinor: 200,
                fuelType: FuelType::Diesel,
                sourceUuid: 'render-bill:delivery',
                bulkTankId: $tank->id,
            ), $workshop->id);

            $listed = app(UnbilledDeliveries::class)->find((string) $delivery->id);

            if ($listed === null) {
                throw new Exception('the posted delivery is not on the unbilled list the picker reads');
            }

            $this->obligationIds[] = (string) $delivery->id;

            return (string) $delivery->id;
        });

        if ($obligationId !== null) {
            $this->attempt('the register offers that delivery as a shape, at the book\'s figure', function () use ($accountant): void {
                // The rendered body is read rather than `assertSee`d: outside a real PHPUnit run the
                // assertion's failure path dies inside its own exporter (§20.20) and hides the needle.
                $html = Livewire::actingAs($accountant)
                    ->test('pages::accounting.bills')
                    ->set('shape', 'fuel_delivery')
                    ->html();

                if (! str_contains($html, 'RENDER-BILL Yard tank')) {
                    throw new Exception('the shape picker did not list the posted delivery');
                }

                if (! str_contains($html, '4000.00 L') || ! str_contains($html, 'P 8 000,00')) {
                    throw new Exception('the listed delivery does not carry its litres and its book figure');
                }
            });

            $this->attempt('a linked bill records with no lines, and the ledger hears nothing new', function () use ($accountant, $supplier, $obligationId): void {
                $entriesBefore = (int) DB::table('gl_journal_entries')->where('company_id', 1)->count();

                Livewire::actingAs($accountant)
                    ->test('pages::accounting.bills')
                    ->set('supplierId', (string) $supplier->id)
                    ->set('shape', 'fuel_delivery')
                    ->set('obligationId', $obligationId)
                    ->set('billOn', Carbon::now()->toDateString())
                    ->set('dueOn', Carbon::now()->addDays(14)->toDateString())
                    ->set('note', 'RENDER-BILL linked paper')
                    ->call('record')
                    ->assertHasNoErrors()
                    ->assertRedirect();

                $bill = DB::table('bills')->where('linked_source', 'fuel_delivery')->where('linked_source_id', $obligationId)->first();

                if ($bill === null) {
                    throw new Exception('the pressed form wrote no linked bill');
                }

                // Claimed before any later assertion: a stale id from the previous section would make the
                // pay checks test a different document than the one this attempt just wrote.
                $this->lastBillId = (string) $bill->id;

                if ((int) $bill->amount !== 800_000 || (int) $bill->tax_amount !== 0) {
                    throw new Exception('the linked bill did not carry the book\'s figure: amount='.$bill->amount.' tax='.$bill->tax_amount);
                }

                if ((int) DB::table('bill_lines')->where('bill_id', $bill->id)->count() !== 0) {
                    throw new Exception('a linked bill grew lines');
                }

                if ((int) DB::table('gl_journal_entries')->where('source_type', 'bill')->where('source_id', $bill->id)->count() !== 0) {
                    throw new Exception('the linked bill posted an entry — the same diesel would be funded twice (§20.36)');
                }

                if ((int) DB::table('gl_journal_entries')->where('company_id', 1)->count() !== $entriesBefore) {
                    throw new Exception('something else was posted while recording a paper that must post nothing');
                }

                if ((string) DB::table('fuel_purchases')->where('id', $obligationId)->value('bill_id') !== (string) $bill->id) {
                    throw new Exception('Fuel\'s door did not stamp the delivery');
                }
            });

            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'wrote no entry of its own', 'the detail says plainly that this paper posted nothing');
            $this->pageContains($accountant, '/accounting/bills', 'documents a bulk fuel delivery', '...and the register names what the row documents');

            $this->attempt('the pay door settles it, and the derived state walks to paid', function () use ($accountant): void {
                $bill = DB::table('bills')->where('id', $this->lastBillId)->first();

                Livewire::actingAs($accountant)
                    ->test('pages::accounting.bill', [$this->lastBillId])
                    ->set('payAmount', '4000.00')
                    ->set('payMethod', 'bank')
                    ->call('pay')
                    ->assertHasNoErrors();

                $half = DB::table('bills')->where('id', $this->lastBillId)->first();

                if ((int) $half->balance_amount !== (int) $bill->amount - 400_000) {
                    throw new Exception('the balance did not walk down by the application: '.$half->balance_amount);
                }

                Livewire::actingAs($accountant)
                    ->test('pages::accounting.bill', [$this->lastBillId])
                    ->set('payAmount', '4000.00')
                    ->call('pay')
                    ->assertHasNoErrors();

                if ((int) DB::table('bills')->where('id', $this->lastBillId)->value('balance_amount') !== 0) {
                    throw new Exception('the bill did not reach exactly zero');
                }

                $debit = (int) DB::table('gl_journal_lines')
                    ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.account_id')
                    ->where('gl_accounts.code', '2110')
                    ->where('gl_journal_lines.bill_id', $this->lastBillId)
                    ->where('gl_journal_lines.direction', 'debit')
                    ->sum('gl_journal_lines.amount_base');

                if ($debit !== 800_000) {
                    throw new Exception('the payable was not debited under the bill dimension: '.$debit);
                }
            });

            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'Money that has gone out', '...and the detail lists the payments that decided it');
            $this->pageContains($accountant, '/accounting/bills/'.$this->lastBillId, 'cannot be voided', '...while the void door refuses a bill the bank has already cleared (§20.33)');
            $this->pageLacks($accountant, '/accounting/bills/'.$this->lastBillId, 'Void this bill', '...and the card is not rendered at all, not merely disabled');
        }
    }

    /** @var list<string> */
    private array $obligationIds = [];

    /**
     * Runs one assertion that also produces a value, so a fixture failure names itself instead of
     * leaving every later check to trip on a null.
     */
    private function attemptValue(string $label, callable $producer): ?string
    {
        try {
            $value = $producer();
            echo "  ok    {$label}\n";

            return $value;
        } catch (Throwable $exception) {
            $this->failures++;
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";

            return null;
        }
    }

    private function staffAs(User $owner, RoleKey $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = 'Render Bill '.$role->label();
        $user->company_id = $owner->company_id;
        $user->password = 'render-check-only-not-a-secret';
        $user->email_verified_at = Carbon::now();
        $user->save();

        CurrentCompany::force($owner->company_id);
        app(ChangeUserRole::class)($user, $role);
        CurrentCompany::forget();

        /** @var User $fresh */
        $fresh = $user->fresh();

        return $fresh;
    }

    /**
     * Reads the rendered body rather than calling PHPUnit's `assertSee`.
     *
     * Outside a real test run PHPUnit's failure path dies inside its own exporter (§20.20), and that
     * AssertionError is what a stale needle used to print. A missing string has to say which one.
     */
    private function pageContains(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new Exception("{$path} answered {$status}, not 200");
            }

            if (! str_contains((string) $response->getContent(), $needle)) {
                throw new Exception("the page did not contain '{$needle}'");
            }
        });
    }

    private function pageLacks(User $user, string $path, string $needle, string $label): void
    {
        $this->attempt($label, function () use ($user, $path, $needle): void {
            $response = $this->actingAs($user, 'web')->get($path);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new Exception("{$path} answered {$status}, not 200");
            }

            if (str_contains((string) $response->getContent(), $needle)) {
                throw new Exception("the page should not contain '{$needle}'");
            }
        });
    }

    private function assertForbidden(User $user, string $path, string $label): void
    {
        $this->attempt($label, function () use ($user, $path): void {
            $status = $this->actingAs($user, 'web')->get($path)->getStatusCode();

            if ($status !== 403) {
                throw new Exception("unexpected status {$status}");
            }
        });
    }

    private function attempt(string $label, callable $assertion): void
    {
        try {
            $assertion();
            echo "  ok    {$label}\n";
        } catch (Throwable $exception) {
            $this->failures++;
            echo '  FAIL  '.$label.' — '.$this->condense($exception->getMessage())."\n";
        }
    }

    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 220);
    }

    private function note(string $message): void
    {
        $this->failures++;
        echo '  FAIL  '.$message."\n";
    }

    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } catch (Throwable $blewUp) {
            $this->failures++;
            echo '  FATAL '.get_class($blewUp).' — '.$this->condense($blewUp->getMessage())."\n".$blewUp->getFile().':'.$blewUp->getLine()."\n";
        } finally {
            $this->cleanUp();

            HandleExceptions::forgetApp();

            $this->tearDown();
        }
    }

    private function cleanUp(): void
    {
        CurrentCompany::force($this->ownerCompany ?? 1);

        $supplierIds = DB::table('suppliers')->where('code', 'like', 'RENDER-BILL%')->pluck('id')->all();
        $billIds = DB::table('bills')
            ->where(fn ($q) => $q->whereIn('supplier_id', $supplierIds ?: [0])->orWhere('note', 'like', 'RENDER-BILL%'))
            ->pluck('id')->all();

        $paymentIds = $supplierIds === [] ? [] : DB::table('supplier_payments')->whereIn('supplier_id', $supplierIds)->pluck('id')->all();
        $purchaseIds = $this->obligationIds === [] ? [] : DB::table('fuel_purchases')->whereIn('id', $this->obligationIds)->pluck('id')->all();

        $entries = array_merge(
            $billIds === [] ? [] : DB::table('gl_journal_entries')
                ->where(fn ($q) => $q->where('source_type', 'like', 'bill%')->whereIn('source_id', $billIds))
                ->pluck('id')->all(),
            $paymentIds === [] ? [] : DB::table('gl_journal_entries')
                ->where('source_type', 'supplier_payment')->whereIn('source_id', $paymentIds)->pluck('id')->all(),
            // The delivery the linked paper documented: its own posting came from Phase 3's door, and
            // the fixture is this run's, so it goes with it — under the barrier, with a written reason.
            $purchaseIds === [] ? [] : DB::table('gl_journal_entries')
                ->where('source_type', 'fuel_purchase')->whereIn('source_id', $purchaseIds)->pluck('id')->all(),
        );

        if ($entries !== []) {
            LedgerBarrier::withLiftedGuard('render-bills: remove this run\'s entries', null, function () use ($entries): void {
                DB::table('gl_journal_lines')->whereIn('entry_id', $entries)->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entries)->whereNotNull('reverses_entry_id')->delete();
                DB::table('gl_journal_entries')->whereIn('id', $entries)->delete();
            });
        }

        if ($billIds !== []) {
            DB::table('bill_lines')->whereIn('bill_id', $billIds)->delete();
            DB::table('operational_events')->where('aggregate_type', 'bill')->whereIn('aggregate_id', $billIds)->delete();

            if ($paymentIds !== []) {
                DB::table('supplier_payment_allocations')->whereIn('supplier_payment_id', $paymentIds)->delete();
            }

            // The stamped delivery names the bill, so it is released before the bill goes.
            if ($purchaseIds !== []) {
                DB::table('fuel_purchases')->whereIn('id', $purchaseIds)->delete();
            }

            DB::table('audits')->whereIn('auditable_type', [Bill::class, BillLine::class])
                ->where(fn ($q) => $q->whereIn('auditable_id', $billIds))
                ->delete();
            DB::table('bills')->whereIn('id', $billIds)->delete();
        }

        if ($paymentIds !== []) {
            DB::table('supplier_payment_allocations')->whereIn('supplier_payment_id', $paymentIds)->delete();
            DB::table('operational_events')->where('aggregate_type', 'supplier_payment')->whereIn('aggregate_id', $paymentIds)->delete();
            DB::table('audits')->where('auditable_type', SupplierPayment::class)->whereIn('auditable_id', $paymentIds)->delete();
            DB::table('supplier_payments')->whereIn('id', $paymentIds)->delete();
        }

        $tankIds = DB::table('bulk_tanks')->where('name', 'like', 'RENDER-BILL%')->pluck('id')->all();

        if ($tankIds !== []) {
            DB::table('bulk_tank_movements')->whereIn('bulk_tank_id', $tankIds)->delete();
            DB::table('bulk_tanks')->whereIn('id', $tankIds)->delete();
        }

        DB::table('suppliers')->where('code', 'like', 'RENDER-BILL%')->delete();
        DB::table('trucks')->where('registration_no', 'like', '%RB1')->delete();

        $checkIds = DB::table('users')->where('email', 'like', 'render-bill-%@fleetguard.local')->pluck('id')->all();

        DB::table('number_sequences')->whereIn('last_used_by', $checkIds ?: [0])->update(['last_used_by' => null]);
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $checkIds ?: [0])->delete();
        DB::table('users')->whereIn('id', $checkIds ?: [0])->delete();

        // §20.8: nothing this run burned may stay burned without a document surviving behind it.
        $rewind = function (string $scope, string $table, string $column): void {
            foreach (DB::table('number_sequences')->where('company_id', $this->ownerCompany ?? 1)->where('scope', $scope)->get() as $row) {
                $highest = DB::table($table)->where('company_id', $this->ownerCompany ?? 1)->whereNotNull($column)
                    ->pluck($column)->map(static function (string $number): int {
                        $dash = strrpos($number, '-');

                        return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
                    })->max() ?? 0;

                DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
            }
        };

        $rewind('bill', 'bills', 'number');
        $rewind('supplier_payment', 'supplier_payments', 'number');

        CurrentCompany::forget();

        echo LedgerBarrier::isLifted()
            ? "  FAIL  the barrier row was left lifted\n"
            : "  ok    the barrier row is lowered — ledger immutability is back on\n";
    }
}

$sweep = new BillsRenderSweep('sweep');
$sweep->drive();

echo "\n".($sweep->failures === 0 ? "RENDER OK\n" : $sweep->failures." render failure(s)\n");

exit($sweep->failures === 0 ? 0 : 1);
