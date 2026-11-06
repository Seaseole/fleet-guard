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
use App\Domain\Operations\Queries\TripBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 4's first door: a delivered trip becomes a fiscal document, numbered and posted.
 *
 * One Action, one transaction, five things that must not happen separately:
 *
 * 1. **The charges are claimed** through Operations' own door, so a delivery cannot be billed twice and a
 *    lost race rolls back cleanly — see {@see MarkTripChargesBilled}.
 * 2. **The number is allocated at issue**, inside this transaction: `INV-{PERIOD}-{SEQ:7}` comes
 *    from the row-locked sequence, gaps mean a missing fiscal document rather than a slow month, and a
 *    rolled-back issue un-burns its value. The invoice is created already `issued` because the design has no
 *    draft that spends a number — `invoices_number_only_when_issued` would refuse one.
 * 3. **VAT is computed per line and rounded once**, from the rate effective on the issue date, so a
 *    December invoice printed in March still carries December's percentage.
 * 4. **The ledger is posted through its only door**: Dr `1150` for the gross, Cr each line's own
 *    revenue account for the net, Cr `2125 Output VAT` for the tax. Revenue is credited at *line* level
 *    because `4100` requires truck, trip **and** customer, which is why a consolidated invoice could
 *    never post one lump sum.
 * 5. **The trip's projection is refreshed**, because this is the first posting that puts revenue on
 *    it — left `revenue_base` honestly at zero until an invoice existed.
 *
 * **Currency, since.** The invoice is issued in the currency the job was quoted in. The
 * rate on its block is not invented — it is *derived* from the two figures the chargeables
 * already froze, and marked `contract` because that is where it came from. What the
 * books carry is the sum of the frozen bases, never a re-computation, so the corridor can bill
 * in ZAR and the trial balance still ties.
 */
final class IssueInvoice
{
    /** The AR control account every issued invoice debits. */
    private const RECEIVABLE_ACCOUNT = '1150';

    /** The output-VAT account sales credit (; `2120` stays the purchase side Phase 3 already used). */
    private const OUTPUT_VAT_ACCOUNT = '2125';

    public function __construct(
        private readonly TripBilling $billing,
        private readonly MarkTripChargesBilled $claimCharges,
        private readonly RefreshTripRollup $refreshRollup,
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(string $tripId, ?int $actorId = null): Invoice
    {
        if (! Gate::allows('invoices.issue')) {
            throw new InvalidArgumentException('Issuing an invoice needs the invoices.issue ability.');
        }

        // The idempotency key is the trip, not a caller-supplied uuid: issuing the same trip twice is a
        // retry until somebody deliberately credits it, and a second click must return the first document
        // rather than spend a second number on it.
        $existing = Invoice::query()
            ->where('source_uuid', $this->sourceUuid($tripId))
            ->where('status', '!=', InvoiceLifecycle::Void->value)
            ->first();

        if ($existing !== null) {
            return $existing->load('lines');
        }

        $bill = $this->billing->forTrip($tripId)
            ?? throw new InvalidArgumentException('That trip is not in this company\'s register, so there is nothing to bill.');

        if ($bill->charges === []) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s has no charges ready to bill. A delivery earns its chargeable when the office approves the '
                .'proof, and an invoice for nothing is not a zero-value invoice.',
                $bill->tripNo,
            ));
        }

        $this->assertOneCustomerPerInvoice($bill->charges, $bill->tripNo);
        $this->assertOneCurrency($bill->charges, $bill->currencyCode, $bill->tripNo);
        $this->assertTruck($bill->truckId, $bill->tripNo);

        $customer = Customer::query()->find($bill->customerId)
            ?? throw new InvalidArgumentException('The job that carries these charges has no customer in the register, so the invoice has no party to address.');

        $terms = PaymentTerms::normalise($customer->payment_terms);

        if (! PaymentTerms::isValid($terms)) {
            throw new InvalidArgumentException(sprintf(
                'Customer %s has payment terms "%s", which are not a term the ageing axis can read. Set cash or '
                .'net_<days> before issuing.',
                $customer->name,
                $customer->payment_terms,
            ));
        }

        $baseCurrency = CurrentCompany::model()->base_currency_code;

        // The discipline, not a refusal: a ZAR bill bills in ZAR. What the invoice will carry
        // in the books is the sum of the chargeables' *own* frozen base amounts — the
        // rate below is derived from those two facts, never imposed on them.

        $this->assertBaseMatchesTheCharge($bill->charges, $bill->tripNo, $bill->currencyCode, $baseCurrency);

        $issuedOn = Carbon::now()->startOfDay();
        $dueOn = PaymentTerms::dueOn($terms, $issuedOn);

        $rate = TaxRate::effectiveOn($issuedOn)
            ?? throw new InvalidArgumentException(
                'No VAT rate applies on '.$issuedOn->toDateString().'. Seed the tax_rates register (TaxRateSeeder '
                .'provides Q4\'s 12% default) before issuing a tax invoice — an invoice with no rate would '
                .'silently report zero VAT.',
            );

        $accounts = $this->chart->byCode();
        $receivable = $accounts[self::RECEIVABLE_ACCOUNT] ?? null;
        $outputVat = $accounts[self::OUTPUT_VAT_ACCOUNT] ?? null;

        if ($receivable === null) {
            throw new InvalidArgumentException('Account 1150 Accounts receivable is not in this company\'s chart, so the invoice cannot be posted.');
        }

        if ($outputVat === null) {
            throw new InvalidArgumentException('Account 2125 Output VAT is not in this company\'s chart, so the invoice cannot be posted.');
        }

        $lines = $this->buildLines($bill->charges, $rate, $accounts);
        $netMinor = array_sum(array_column($lines, 'amount'));
        $taxMinor = array_sum(array_column($lines, 'tax_amount'));
        $grossMinor = $netMinor + $taxMinor;
        $baseNetMinor = array_sum(array_column($lines, 'base_amount'));
        $baseTaxMinor = array_sum(array_column($lines, 'tax_base'));
        $grossBaseMinor = $baseNetMinor + $baseTaxMinor;

        // On a foreign document: the rate is *derived* from the two frozen totals — the
        // lines' base amounts were frozen when the chargeables were priced, and the design forbids
        // recomputing any of them from a rate read today. The derived rate is informational
        // (8 dp HALF_UP, Money's own implied-rate helper); `contract` names where it came from:
        // the agreed quote, not the bulletin.
        $documentCurrency = $bill->currencyCode;
        $isForeign = $documentCurrency !== $baseCurrency;

        // `invoices_rate_positive` is a NOT NULL CHECK, and the implied-rate helper divides by the
        // foreign total, so a foreign bill with nothing to bill has no rate to freeze. Refusing it
        // names the two real remedies instead of leaving a 0.00000001 in the ledger's path.
        if ($isForeign && ($grossMinor <= 0 || $grossBaseMinor <= 0)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s bills in %s but its frozen gross is %s minor units against a base gross of %s %s — a '
                .'foreign invoice with no amount has no rate to freeze. Credit the chargeables or bill the '
                .'trip in %s.',
                $bill->tripNo,
                $documentCurrency,
                $grossMinor,
                $grossBaseMinor,
                $baseCurrency,
                $baseCurrency,
            ));
        }

        $fxRate = $isForeign
            ? Money::ofMinor($grossMinor, $documentCurrency)->rateToBaseImpliedBy(Money::ofMinor($grossBaseMinor, $baseCurrency))
            : '1';

        $description = sprintf(
            'Trip %s · %s · %d line%s',
            $bill->tripNo,
            $customer->name,
            count($lines),
            count($lines) === 1 ? '' : 's',
        );

        return DB::transaction(function () use (
            $tripId, $actorId, $bill, $customer, $terms, $dueOn, $issuedOn, $rate, $lines, $accounts,
            $netMinor, $taxMinor, $grossMinor, $receivable, $outputVat, $baseCurrency, $description,
            $documentCurrency, $fxRate, $isForeign, $baseNetMinor, $baseTaxMinor, $grossBaseMinor,
        ): Invoice {
            // 1. Claim first: the contested resource is the delivery, so the refusal (if any) happens
            //    before a fiscal number is spent or a journal entry is written.
            ($this->claimCharges)($bill->tripId, array_map(static fn (array $line): string => $line['chargeable_id'], $lines));

            // 2. The number this document will carry, allocated under the sequence row lock.
            $number = $this->numbers->allocate('invoice', $issuedOn, $actorId);

            $invoice = Invoice::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => InvoiceLifecycle::Issued->value,
                'customer_id' => $customer->id,
                'job_id' => $bill->jobId,
                'kind' => InvoiceKind::Trip->value,
                'issue_date' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'terms_snapshot' => $terms,
                // Verbatim. A base bill is the identity block shipped; a foreign one
                // carries its currency's totals beside the frozen base totals, the derived rate
                // marked `contract` because it came from the quote, not a rate feed.
                'currency_code' => $documentCurrency,
                'amount' => $netMinor,
                'fx_rate' => $fxRate,
                'fx_rate_source' => $isForeign ? FxRateSource::Contract->value : FxRateSource::Manual->value,
                'fx_rate_date' => $issuedOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $baseNetMinor,
                'tax_amount' => $taxMinor,
                'tax_base' => $baseTaxMinor,
                'balance_amount' => $grossMinor,
                'balance_base' => $grossBaseMinor,
                'source_uuid' => $this->sourceUuid($tripId),
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
                    'trip_id' => $bill->tripId,
                    'job_id' => $bill->jobId,
                    'truck_id' => $bill->truckId,
                    'revenue_account_code' => $line['line_kind']->accountCode(),
                    'quantity_x100' => 100,
                    'unit_price_minor' => $line['amount'],
                    'currency_code' => $documentCurrency,
                    'amount' => $line['amount'],
                    'base_amount' => $line['base_amount'],
                    'tax_rate_id' => $rate->id,
                    'tax_kind' => $rate->kind->value,
                    'tax_amount' => $line['tax_amount'],
                    'tax_base' => $line['tax_base'],
                    'position' => $line['position'],
                ]);
            }

            $this->post($invoice, $lines, $accounts, $receivable, $outputVat, $bill->customerId, $bill->jobId, $bill->tripId, $bill->truckId, $issuedOn, $baseCurrency, $description, $actorId);

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $invoice->id,
                fromState: 'draft',
                toState: 'issued',
                context: [
                    'number' => $number,
                    'trip_id' => $bill->tripId,
                    'customer_id' => $bill->customerId,
                    'amount' => $netMinor,
                    'tax' => $taxMinor,
                    'chargeables' => array_column($lines, 'chargeable_id'),
                ],
                actorId: $actorId,
            );

            // 5. Revenue is now on the ledger, so the trip's projection can finally carry it.
            ($this->refreshRollup)($bill->tripId);

            return $invoice->refresh()->load('lines');
        }, 3);
    }

    /**
     * One line per chargeable, with its VAT rounded at line level.
     *
     * The line's "quantity" is 100 — one unit at the chargeable's own price — because a chargeable is
     * already the agreed amount for a delivered quantity; splitting it into tonnes here would invent a
     * rate per tonne the POD never stated. `invoice_lines_amount_is_rounded_product` holds exactly:
     * amount × 100 = quantity × unit price.
     *
     * @param  list<BillableCharge>  $charges
     * @param  array<string, ChartAccount>  $accounts
     * @return list<array{chargeable_id: string, line_kind: InvoiceLineKind, description: string, amount: int, base_amount: int, tax_amount: int, tax_base: int, position: int}>
     */
    private function buildLines(array $charges, TaxRate $rate, array $accounts): array
    {
        $lines = [];

        foreach (array_values($charges) as $index => $charge) {
            $kind = InvoiceLineKind::tryFrom($charge->type->value)
                ?? throw new InvalidArgumentException(sprintf(
                    'Chargeable type "%s" has no invoice line kind, so it cannot be billed.',
                    $charge->type->value,
                ));

            if (! isset($accounts[$kind->accountCode()])) {
                throw new InvalidArgumentException(sprintf(
                    'Account %s is not in this company\'s chart, so a %s line cannot be posted.',
                    $kind->accountCode(),
                    $kind->label(),
                ));
            }

            if ($charge->amountMinor <= 0) {
                throw new InvalidArgumentException(sprintf('The charge "%s" is not a positive amount, so it cannot be billed.', $charge->description));
            }

            $tax = $rate->taxOn($charge->amountMinor);

            $lines[] = [
                'chargeable_id' => $charge->id,
                'line_kind' => $kind,
                'description' => mb_substr($charge->description, 0, 160),
                'amount' => $charge->amountMinor,
                'base_amount' => $charge->baseAmountMinor,
                'tax_amount' => $tax,
                // The document and the ledger are both in the base currency in this slice, so the tax on
                // the converted amount is the tax on the document amount.
                'tax_base' => $rate->taxOnBase($charge->baseAmountMinor),
                'position' => $index + 1,
            ];
        }

        return $lines;
    }

    /**
     * The `InvoiceIssued`, one entry: the receivable gross, the revenue net per line, the VAT once.
     *
     * Every line carries `invoice_id` so the AR ageing and the invoice's own journal are a query on the
     * ledger, and the revenue lines carry the leg's dimensions because `4100` cannot be posted
     * without truck, trip and customer. The VAT line carries the customer because output VAT is
     * somebody's, even though the account requires no dimension.
     *
     * @param  list<array{chargeable_id: string, line_kind: InvoiceLineKind, description: string, amount: int, base_amount: int, tax_amount: int, tax_base: int, position: int}>  $lines
     * @param  array<string, ChartAccount>  $accounts
     */
    private function post(
        Invoice $invoice,
        array $lines,
        array $accounts,
        ChartAccount $receivable,
        ChartAccount $outputVat,
        int $customerId,
        string $jobId,
        string $tripId,
        int $truckId,
        Carbon $issuedOn,
        string $baseCurrency,
        string $description,
        ?int $actorId,
    ): void {
        $ledgerLines = [
            new LineDto(
                accountId: $receivable->id,
                side: Side::Debit,
                // balance_base, not balance_amount: the receivable the books carry is the frozen
                // base figure even when the document speaks ZAR (the ledger never learns
                // a currency it did not freeze at write time).
                base: Money::ofMinor($invoice->balance_base, $baseCurrency),
                note: $invoice->reference(),
                dimensions: array_filter([
                    'customer_id' => $customerId,
                    'job_id' => $jobId,
                    'invoice_id' => $invoice->id,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ),
        ];

        $revenueDimensions = array_filter([
            'customer_id' => $customerId,
            'job_id' => $jobId,
            'trip_id' => $tripId,
            'truck_id' => $truckId,
            'invoice_id' => $invoice->id,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        foreach ($lines as $line) {
            $account = $accounts[$line['line_kind']->accountCode()] ?? null;

            if ($account === null) {
                throw new InvalidArgumentException(sprintf('Account %s vanished from the chart between validation and posting.', $line['line_kind']->accountCode()));
            }

            $ledgerLines[] = new LineDto(
                accountId: $account->id,
                side: Side::Credit,
                base: Money::ofMinor($line['base_amount'], $baseCurrency),
                note: $line['description'],
                dimensions: $revenueDimensions,
            );
        }

        if ($invoice->tax_amount > 0) {
            $ledgerLines[] = new LineDto(
                accountId: $outputVat->id,
                side: Side::Credit,
                base: Money::ofMinor($invoice->tax_base, $baseCurrency),
                note: 'Output VAT',
                dimensions: array_filter([
                    'customer_id' => $customerId,
                    'invoice_id' => $invoice->id,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
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

    private function sourceUuid(string $tripId): string
    {
        return 'trip:'.$tripId;
    }

    /** @param list<BillableCharge> $charges */
    private function assertOneCustomerPerInvoice(array $charges, string $tripNo): void
    {
        $customers = array_values(array_unique(array_map(static fn (BillableCharge $charge): int => $charge->customerId, $charges)));

        if (count($customers) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s carries charges for %d customers. One invoice is addressed to one party; a consolidated '
                .'billing run groups by customer.',
                $tripNo,
                count($customers),
            ));
        }
    }

    /** @param list<BillableCharge> $charges */
    private function assertOneCurrency(array $charges, string $currency, string $tripNo): void
    {
        foreach ($charges as $charge) {
            if ($charge->currencyCode !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'Trip %s carries charges in both %s and %s. One document states one currency.',
                    $tripNo,
                    $currency,
                    $charge->currencyCode,
                ));
            }
        }
    }

    private function assertTruck(int $truckId, string $tripNo): void
    {
        if ($truckId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s has no truck, so its freight line cannot name the leg account 4100 requires. '
                .'Plan and dispatch the trip first.',
                $tripNo,
            ));
        }
    }

    /**
     * The identity check that made the base-only slice safe, widened for rather than
     * dropped: in base currency, amount and base must still match exactly (rate 1 is a fact,
     * and a chargeable that broke it was written by a defect — billing one would make the
     * invoice's FX block a lie). In a foreign currency the pair is allowed to differ —
     * that difference *is* the conversion — but each chargeable's own figures must be
     * internally sane: positive quoted amount, positive booked base, no sign games.
     *
     * @param  list<BillableCharge>  $charges
     */
    private function assertBaseMatchesTheCharge(array $charges, string $tripNo, string $documentCurrency, string $baseCurrency): void
    {
        foreach ($charges as $charge) {
            if ($charge->amountMinor <= 0 || $charge->baseAmountMinor <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'The charge "%s" on trip %s carries a non-positive figure (%d %s / %d base); nothing sane can be billed from it.',
                    $charge->description,
                    $tripNo,
                    $charge->amountMinor,
                    $charge->currencyCode,
                    $charge->baseAmountMinor,
                ));
            }

            if ($documentCurrency === $baseCurrency && $charge->baseAmountMinor !== $charge->amountMinor) {
                throw new InvalidArgumentException(sprintf(
                    'The charge "%s" on trip %s says %d %s but %d in base currency. In one currency those are the '
                    .'same number, so the chargeable is inconsistent and cannot be billed.',
                    $charge->description,
                    $tripNo,
                    $charge->amountMinor,
                    $charge->currencyCode,
                    $charge->baseAmountMinor,
                ));
            }
        }
    }
}
