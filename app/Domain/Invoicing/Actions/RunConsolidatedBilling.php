<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Actions\MarkTripChargesBilled;
use App\Domain\Operations\Actions\RefreshTripRollup;
use App\Domain\Operations\Data\BillableCharge;
use App\Domain\Operations\Data\TripBill;
use App\Domain\Operations\Queries\CustomerBilling;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * §10.4's consolidated billing run: one customer, one period, one document.
 *
 * It is the same door as {@see IssueInvoice} seen from the other side. Per-trip invoicing asks "what
 * does this leg owe"; a monthly customer asks "what have I delivered that you have not been billed
 * for", and the answer is a document that bundles several trips (§16 R11). The pieces that must not
 * be shared are shared: the number comes from the same gap-free `invoice` scope, VAT is rounded per
 * line from the rate effective on the issue date, the charges are claimed under Operations' own row
 * lock, and the ledger entry is one `InvoiceIssued` with a credit per line — because `4100` requires
 * truck, trip and customer (§6.1), a consolidated invoice can never post one lump revenue figure.
 *
 * **What makes it a run rather than a loop over trips.** The customer, the terms and the due date are
 * decided once for the document, the charge window is the caller's (a month, a fortnight, "everything
 * ready"), and the line positions follow delivery order across trips, so a statement prints the month
 * in the order the work happened. A trip whose charges were already billed simply does not appear:
 * the read door returns `ready` rows only.
 *
 * **The refused cases are the ones a run must never paper over**: no ready charges (a statement for
 * nothing is not a zero-value document), charges in more than one currency (one document states one
 * currency, §5.2), a currency that is not the company's base (FX is a separate slice), and a
 * chargeable whose base figure disagrees with its own amount (§5.3).
 *
 * Idempotent on `consolidated:{customer}:{from}:{to}`: a retried run returns the document the first
 * one issued rather than spending a second fiscal number on the same month. Charges that become ready
 * after a run belong to the next period — that is what "a period statement" means.
 */
final class RunConsolidatedBilling
{
    private const RECEIVABLE_ACCOUNT = '1150';

    private const OUTPUT_VAT_ACCOUNT = '2125';

    public function __construct(
        private readonly CustomerBilling $billing,
        private readonly MarkTripChargesBilled $claimCharges,
        private readonly RefreshTripRollup $refreshRollup,
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(int $customerId, DateTimeInterface $from, DateTimeInterface $to, ?int $actorId = null): Invoice
    {
        if (! Gate::allows('invoices.issue')) {
            throw new InvalidArgumentException('Running a consolidated bill needs the invoices.issue ability (§9.3).');
        }

        $windowStart = Carbon::instance($from)->startOfDay();
        $windowEnd = Carbon::instance($to)->endOfDay();

        if ($windowEnd->lessThan($windowStart)) {
            throw new InvalidArgumentException('A billing run ends after it starts; the window was given backwards.');
        }

        $existing = Invoice::query()
            ->where('source_uuid', $this->sourceUuid($customerId, $windowStart, $windowEnd))
            ->where('status', '!=', InvoiceLifecycle::Void->value)
            ->first();

        if ($existing !== null) {
            return $existing->load('lines');
        }

        $customer = Customer::query()->find($customerId)
            ?? throw new InvalidArgumentException('That customer is not in this company\'s register, so the run has no party to address.');

        $bills = $this->billing->readyForCustomer($customerId, $windowStart, $windowEnd->copy()->addSecond());

        if ($bills === []) {
            throw new InvalidArgumentException(sprintf(
                'Customer %s has no ready charges raised between %s and %s, so there is nothing to consolidate. '
                .'An invoice for nothing is not a zero-value invoice (§10.4).',
                $customer->name,
                $windowStart->toDateString(),
                $windowEnd->toDateString(),
            ));
        }

        $terms = PaymentTerms::normalise($customer->payment_terms);

        if (! PaymentTerms::isValid($terms)) {
            throw new InvalidArgumentException(sprintf(
                'Customer %s has payment terms "%s", which are not a term the ageing axis can read. Set cash or '
                .'net_<days> before issuing (§4.1).',
                $customer->name,
                $customer->payment_terms,
            ));
        }

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        foreach ($bills as $bill) {
            $this->assertOneCurrency($bill);
            $this->assertBaseMatchesTheCharge($bill);
        }

        if (count(array_unique(array_map(static fn (TripBill $bill): string => $bill->currencyCode, $bills))) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'This customer\'s ready charges are raised in more than one currency. One document states one '
                .'currency (§5.2), so bill each currency separately.',
            ));
        }

        if ($bills[0]->currencyCode !== $baseCurrency) {
            throw new InvalidArgumentException(sprintf(
                'These charges were quoted in %s and this run can only issue in %s. Foreign-currency issuance '
                .'arrives with §5.2\'s FX slice — a rate invented here would be a rate nobody agreed.',
                $bills[0]->currencyCode,
                $baseCurrency,
            ));
        }

        $issuedOn = Carbon::now()->startOfDay();
        $dueOn = PaymentTerms::dueOn($terms, $issuedOn);

        $rate = TaxRate::effectiveOn($issuedOn)
            ?? throw new InvalidArgumentException(
                'No VAT rate applies on '.$issuedOn->toDateString().'. Seed the tax_rates register '
                .'(TaxRateSeeder provides §17 Q4\'s 12% default) before issuing a tax invoice — an invoice with '
                .'no rate would silently report zero VAT.',
            );

        $accounts = $this->chart->byCode();
        $receivable = $accounts[self::RECEIVABLE_ACCOUNT] ?? null;
        $outputVat = $accounts[self::OUTPUT_VAT_ACCOUNT] ?? null;

        if ($receivable === null) {
            throw new InvalidArgumentException('Account 1150 Accounts receivable is not in this company\'s chart, so the run cannot be posted (§6.2).');
        }

        if ($outputVat === null) {
            throw new InvalidArgumentException('Account 2125 Output VAT is not in this company\'s chart, so the run cannot be posted (§6.8).');
        }

        $lines = $this->buildLines($bills, $rate, $accounts);
        $netMinor = array_sum(array_column($lines, 'amount'));
        $taxMinor = array_sum(array_column($lines, 'tax_amount'));
        $grossMinor = $netMinor + $taxMinor;

        $description = sprintf(
            'Statement %s · %s — %d trip%s, %d line%s',
            $windowStart->toDateString().' to '.$windowEnd->toDateString(),
            $customer->name,
            count($bills),
            count($bills) === 1 ? '' : 's',
            count($lines),
            count($lines) === 1 ? '' : 's',
        );

        return DB::transaction(function () use (
            $actorId, $bills, $customer, $terms, $dueOn, $issuedOn, $rate, $lines, $accounts,
            $netMinor, $taxMinor, $grossMinor, $receivable, $outputVat, $baseCurrency, $description,
            $customerId, $windowStart, $windowEnd,
        ): Invoice {
            // 1. Claim every trip's charges under the same lock the per-trip door uses, before a number is
            //    spent. A run that loses a race rolls back wholly — including the number — so the month is
            //    either billed once or not at all.
            foreach ($bills as $bill) {
                ($this->claimCharges)($bill->tripId, array_map(
                    static fn (BillableCharge $charge): string => $charge->id,
                    $bill->charges,
                ));
            }

            // 2. §7.4 — the same gap-free scope as any other invoice.
            $number = $this->numbers->allocate('invoice', $issuedOn, $actorId);

            $invoice = Invoice::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => InvoiceLifecycle::Issued->value,
                'customer_id' => $customer->id,
                // Many jobs, one customer: the document names no single job, and the line keeps the leg.
                'job_id' => null,
                'kind' => InvoiceKind::Consolidated->value,
                'issue_date' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'terms_snapshot' => $terms,
                'currency_code' => $baseCurrency,
                'amount' => $netMinor,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $issuedOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $netMinor,
                'tax_amount' => $taxMinor,
                'tax_base' => $taxMinor,
                'balance_amount' => $grossMinor,
                'balance_base' => $grossMinor,
                'source_uuid' => $this->sourceUuid($customerId, $windowStart, $windowEnd),
                'issued_by' => $actorId,
                'issued_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                InvoiceLine::create([
                    'id' => (string) Str::ulid(),
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'line_kind' => $line['line_kind']->value,
                    'chargeable_id' => $line['chargeable_id'],
                    // Per line, not per document: a statement credits each leg's own revenue account with
                    // that leg's own truck and trip (§6.1).
                    'trip_id' => $line['trip_id'],
                    'job_id' => $line['job_id'],
                    'truck_id' => $line['truck_id'],
                    'revenue_account_code' => $line['line_kind']->accountCode(),
                    'quantity_x100' => 100,
                    'unit_price_minor' => $line['amount'],
                    'currency_code' => $baseCurrency,
                    'amount' => $line['amount'],
                    'base_amount' => $line['base_amount'],
                    'tax_rate_id' => $rate->id,
                    'tax_kind' => $rate->kind->value,
                    'tax_amount' => $line['tax_amount'],
                    'tax_base' => $line['tax_base'],
                    'position' => $line['position'],
                ]);
            }

            $this->post($invoice, $lines, $accounts, $receivable, $outputVat, $customerId, $issuedOn, $baseCurrency, $description, $actorId);

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $invoice->id,
                fromState: 'draft',
                toState: 'issued',
                context: [
                    'number' => $number,
                    'kind' => InvoiceKind::Consolidated->value,
                    'customer_id' => $customerId,
                    'window_from' => $windowStart->toDateString(),
                    'window_to' => $windowEnd->toDateString(),
                    'trips' => array_map(static fn (TripBill $bill): string => $bill->tripId, $bills),
                    'amount' => $netMinor,
                    'tax' => $taxMinor,
                ],
                actorId: $actorId,
            );

            foreach ($bills as $bill) {
                ($this->refreshRollup)($bill->tripId);
            }

            return $invoice->refresh()->load('lines');
        }, 3);
    }

    /**
     * One line per chargeable, carrying the trip it was earned on.
     *
     * The description is prefixed with the trip number because a statement's reader is reconciling a
     * month of deliveries, not one leg: "TRP-00042 · Gaborone → Johannesburg" is the sentence that lets
     * them find the POD.
     *
     * @param  list<TripBill>  $bills
     * @param  array<string, ChartAccount>  $accounts
     * @return list<array{chargeable_id: string, line_kind: InvoiceLineKind, description: string, trip_id: string, job_id: string, truck_id: int, amount: int, base_amount: int, tax_amount: int, tax_base: int, position: int}>
     */
    private function buildLines(array $bills, TaxRate $rate, array $accounts): array
    {
        $lines = [];

        foreach ($bills as $bill) {
            foreach ($bill->charges as $charge) {
                $kind = InvoiceLineKind::tryFrom($charge->type->value)
                    ?? throw new InvalidArgumentException(sprintf(
                        'Chargeable type "%s" has no invoice line kind (§4.1), so it cannot be billed.',
                        $charge->type->value,
                    ));

                if (! isset($accounts[$kind->accountCode()])) {
                    throw new InvalidArgumentException(sprintf(
                        'Account %s is not in this company\'s chart, so a %s line cannot be posted (§6.8).',
                        $kind->accountCode(),
                        $kind->label(),
                    ));
                }

                if ($charge->amountMinor <= 0) {
                    throw new InvalidArgumentException(sprintf('The charge "%s" is not a positive amount, so it cannot be billed.', $charge->description));
                }

                $lines[] = [
                    'chargeable_id' => $charge->id,
                    'line_kind' => $kind,
                    'description' => mb_substr($bill->tripNo.' · '.$charge->description, 0, 160),
                    'trip_id' => $charge->tripId,
                    'job_id' => $charge->jobId,
                    'truck_id' => $charge->truckId,
                    'amount' => $charge->amountMinor,
                    'base_amount' => $charge->baseAmountMinor,
                    'tax_amount' => $rate->taxOn($charge->amountMinor),
                    'tax_base' => $rate->taxOnBase($charge->baseAmountMinor),
                    'position' => count($lines) + 1,
                ];
            }
        }

        return $lines;
    }

    /**
     * §6.8's `InvoiceIssued` once, with each line's leg on its revenue credit.
     *
     * The receivable and the VAT credit carry `customer_id` and `invoice_id` only: the document is one
     * party's, and a consolidated AR line cannot sit in one trip's bucket without lying about the other
     * legs on the same statement.
     *
     * @param  list<array{chargeable_id: string, line_kind: InvoiceLineKind, description: string, trip_id: string, job_id: string, truck_id: int, amount: int, base_amount: int, tax_amount: int, tax_base: int, position: int}>  $lines
     * @param  array<string, ChartAccount>  $accounts
     */
    private function post(
        Invoice $invoice,
        array $lines,
        array $accounts,
        ChartAccount $receivable,
        ChartAccount $outputVat,
        int $customerId,
        Carbon $issuedOn,
        string $baseCurrency,
        string $description,
        ?int $actorId,
    ): void {
        $ledgerLines = [
            new LineDto(
                accountId: $receivable->id,
                side: Side::Debit,
                base: Money::ofMinor($invoice->balance_amount, $baseCurrency),
                note: $invoice->reference(),
                dimensions: [
                    'customer_id' => $customerId,
                    'invoice_id' => $invoice->id,
                ],
            ),
        ];

        foreach ($lines as $line) {
            $account = $accounts[$line['line_kind']->accountCode()] ?? null;

            if ($account === null) {
                throw new InvalidArgumentException(sprintf('Account %s vanished from the chart between validation and posting (§6.8).', $line['line_kind']->accountCode()));
            }

            $ledgerLines[] = new LineDto(
                accountId: $account->id,
                side: Side::Credit,
                base: Money::ofMinor($line['base_amount'], $baseCurrency),
                note: $line['description'],
                dimensions: array_filter([
                    'customer_id' => $customerId,
                    'job_id' => $line['job_id'],
                    'trip_id' => $line['trip_id'],
                    'truck_id' => $line['truck_id'],
                    'invoice_id' => $invoice->id,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        }

        if ($invoice->tax_amount > 0) {
            $ledgerLines[] = new LineDto(
                accountId: $outputVat->id,
                side: Side::Credit,
                base: Money::ofMinor($invoice->tax_base, $baseCurrency),
                note: 'Output VAT',
                dimensions: [
                    'customer_id' => $customerId,
                    'invoice_id' => $invoice->id,
                ],
            );
        }

        $this->ledger->post(new PostingDto(
            description: $description,
            postedOn: $issuedOn,
            currencyCode: $baseCurrency,
            fxRate: '1',
            fxRateSource: FxRateSource::Manual,
            fxRateDate: $issuedOn,
            lines: $ledgerLines,
            sourceType: 'invoice',
            sourceId: $invoice->id,
            createdBy: $actorId,
        ));
    }

    private function sourceUuid(int $customerId, DateTimeInterface $from, DateTimeInterface $to): string
    {
        return sprintf(
            'consolidated:%d:%s:%s',
            $customerId,
            CarbonImmutable::instance($from)->toDateString(),
            CarbonImmutable::instance($to)->toDateString(),
        );
    }

    private function assertOneCurrency(TripBill $bill): void
    {
        foreach ($bill->charges as $charge) {
            if ($charge->currencyCode !== $bill->currencyCode) {
                throw new InvalidArgumentException(sprintf(
                    'Trip %s carries charges in both %s and %s. One document states one currency (§5.2).',
                    $bill->tripNo,
                    $bill->currencyCode,
                    $charge->currencyCode,
                ));
            }
        }
    }

    private function assertBaseMatchesTheCharge(TripBill $bill): void
    {
        foreach ($bill->charges as $charge) {
            if ($charge->baseAmountMinor !== $charge->amountMinor) {
                throw new InvalidArgumentException(sprintf(
                    'The charge "%s" on trip %s says %d %s but %d in base currency. In one currency those are the '
                    .'same number, so the chargeable is inconsistent and cannot be billed (§5.3).',
                    $charge->description,
                    $bill->tripNo,
                    $charge->amountMinor,
                    $charge->currencyCode,
                    $charge->baseAmountMinor,
                ));
            }
        }
    }
}
