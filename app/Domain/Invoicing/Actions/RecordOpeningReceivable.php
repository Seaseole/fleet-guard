<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 0.5's opening receivable — last year's invoice, arriving through the cutover intake.
 *
 * The trial balance cannot carry this. `1150` demands a customer, so an aggregate opening
 * journal line is refused by the ledger and the ageing report would be empty on the day the fleet
 * most needs it. This Action therefore makes the receivable a **document**: it is issued, numbered,
 * addressable, and posts its own balanced entry, exactly like an invoice raised from a delivery.
 *
 * Three things distinguish an opening document from a live one, and all three are accounting, not
 * convenience:
 *
 * 1. **No VAT, and no revenue.** The debit is `1150`, the credit is `3010` Owner capital. The freight
 *    was earned — and its VAT was filed — in the old system's year; crediting `4100` here would
 *    report the same trip's income twice across two books (the discipline applied to years).
 * 2. **The document is dated when it was raised, the entry when it was struck.** `issue_date` and
 *    `due_on` carry the legacy truth, which is what puts a 90-day-old item in the right ageing bucket
 *     on day one. The journal posts **today**, because an opening balance is a fact about cutover day
 *     and posting it backwards would fight the closed periods. The fiscal number likewise comes
 *     from today's sequence: the register is starting, not being back-filled.
 * 3. **`source_uuid` is the natural key.** `opening:{customer code}:{legacy reference}` makes the row
 *     idempotent — the intake's contract is that a batch which dies halfway can be applied again
 *     without paying the customer's debt twice.
 *
 * A corridor customer's open item stays in the currency it will be paid in. Since the
 * settlement door only accepts a receipt of the document's own currency, so forcing a ZAR balance into
 * a BWP invoice would strand every future ZAR payment on `2150`. The rate is the cutover-day figure,
 * and `opening_balance` says so in `fx_rate_source`.
 */
final class RecordOpeningReceivable
{
    private const RECEIVABLE_ACCOUNT = '1150';

    private const OPENING_EQUITY_ACCOUNT = '3010';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  int  $amountMinor  the outstanding figure, in the document's own currency
     * @param  string|null  $currencyCode  the legacy invoice's currency; the base currency when blank
     * @param  string|null  $fxRate  1 unit of that currency in base, on cutover day
     */
    public function execute(
        int $customerId,
        int $amountMinor,
        DateTimeInterface $issuedOn,
        DateTimeInterface $dueOn,
        string $legacyReference,
        ?string $description = null,
        ?string $currencyCode = null,
        ?string $fxRate = null,
        ?int $actorId = null,
        ?DateTimeInterface $postedOn = null,
    ): Invoice {
        if (! Gate::allows('invoices.issue')) {
            throw new InvalidArgumentException('Opening a receivable on the ledger needs the invoices.issue ability.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An opening receivable is a positive amount owed; nothing can be posted from a zero or a credit.');
        }

        $reference = trim($legacyReference);

        if ($reference === '' || mb_strlen($reference) > 40) {
            throw new InvalidArgumentException('The legacy document number is the natural key of an opening row — it must be present and no longer than 40 characters.');
        }

        $customer = Customer::query()->find($customerId)
            ?? throw new InvalidArgumentException('No customer in this company\'s register carries that id, so the opening receivable has no debtor.');

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $currency = $currencyCode === null || $currencyCode === '' ? $baseCurrency : strtoupper($currencyCode);
        $isForeign = $currency !== $baseCurrency;

        if ($isForeign && ($fxRate === null || $fxRate === '')) {
            throw new InvalidArgumentException(sprintf(
                'A %s opening balance needs the rate it is worth in %s on cutover day: the books take the base figure, '
                .'and only the accountant\'s sheet says which one it is.',
                $currency,
                $baseCurrency,
            ));
        }

        if (! $isForeign && $fxRate !== null && $fxRate !== '' && (float) $fxRate !== 1.0) {
            throw new InvalidArgumentException('A base-currency opening balance has no rate to state — 1 unit of the base currency is 1 unit.');
        }

        $raisedOn = Carbon::instance($issuedOn)->startOfDay();
        $payableBy = Carbon::instance($dueOn)->startOfDay();
        $struckOn = Carbon::instance($postedOn ?? Carbon::now(CurrentCompany::timezone()))->startOfDay();

        if ($payableBy->lt($raisedOn)) {
            throw new InvalidArgumentException(sprintf(
                'The opening item %s for %s is due %s but was issued %s — a document cannot fall due before it exists.',
                $reference,
                $customer->name,
                $payableBy->toDateString(),
                $raisedOn->toDateString(),
            ));
        }

        $sourceUuid = 'opening:'.$customer->code.':'.$reference;

        /** @var Invoice|null $already */
        $already = Invoice::query()->where('source_uuid', $sourceUuid)->first();

        if ($already !== null) {
            return $already;
        }

        $terms = PaymentTerms::normalise($customer->payment_terms);

        if (! PaymentTerms::isValid($terms)) {
            throw new InvalidArgumentException(sprintf(
                'Customer %s has payment terms "%s", which the ageing axis cannot read. Set cash or net_<days> on the '
                .'register before importing its open items.',
                $customer->name,
                $customer->payment_terms,
            ));
        }

        $fx = $isForeign
            ? Fx::foreignToBase(
                $amountMinor,
                $currency,
                (string) $fxRate,
                $baseCurrency,
                FxRateSource::OpeningBalance,
                $struckOn->toImmutable(),
            )
            : null;

        $baseMinor = $fx?->baseAmount()->minorUnits ?? $amountMinor;

        if ($baseMinor <= 0) {
            throw new InvalidArgumentException('The converted base figure came out at zero — check the rate against the amount.');
        }

        $accounts = $this->chart->byCode();
        $receivable = $this->account($accounts, self::RECEIVABLE_ACCOUNT);
        $equity = $this->account($accounts, self::OPENING_EQUITY_ACCOUNT);

        return DB::transaction(function () use (
            $customer, $reference, $description, $terms, $raisedOn, $payableBy, $struckOn,
            $amountMinor, $baseMinor, $currency, $baseCurrency, $fx, $sourceUuid,
            $receivable, $equity, $actorId
        ): Invoice {
            $number = $this->numbers->allocate('invoice', $struckOn, $actorId);

            $invoice = Invoice::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => InvoiceLifecycle::Issued->value,
                'customer_id' => $customer->id,
                'job_id' => null,
                'kind' => InvoiceKind::Opening->value,
                // The legacy dates are kept because ageing is the whole point of importing an open
                // item: a 90-day debt must read 90 days late on the day the fleet starts.
                'issue_date' => $raisedOn->toDateString(),
                'due_on' => $payableBy->toDateString(),
                'terms_snapshot' => $terms,
                'currency_code' => $currency,
                'amount' => $amountMinor,
                'fx_rate' => $fx === null ? '1' : $fx->rate,
                'fx_rate_source' => $fx === null ? FxRateSource::OpeningBalance->value : $fx->source->value,
                'fx_rate_date' => $struckOn->toDateString(),
                'fx_rate_ref' => $fx === null ? null : $reference,
                'base_currency_code' => $baseCurrency,
                'base_amount' => $baseMinor,
                // No VAT, ever: it was charged and filed in the old system's year.
                'tax_amount' => 0,
                'tax_base' => 0,
                'balance_amount' => $amountMinor,
                'balance_base' => $baseMinor,
                'po_reference' => null,
                'note' => $description === null || $description === ''
                    ? null
                    : mb_substr($description, 0, 500),
                'source_uuid' => $sourceUuid,
                'issued_by' => $actorId,
                'issued_at' => Carbon::now(),
            ]);

            $dimensions = array_filter([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->ledger->post(PostingDto::inBaseCurrency(
                description: sprintf('Opening receivable %s · %s · legacy %s', $number, $customer->name, $reference),
                postedOn: $struckOn,
                baseCurrencyCode: $baseCurrency,
                lines: [
                    new LineDto(
                        accountId: $receivable->id,
                        side: Side::Debit,
                        base: Money::ofMinor($baseMinor, $baseCurrency),
                        note: $reference,
                        dimensions: $dimensions,
                    ),
                    new LineDto(
                        accountId: $equity->id,
                        side: Side::Credit,
                        base: Money::ofMinor($baseMinor, $baseCurrency),
                        note: 'opening balance',
                        dimensions: $dimensions,
                    ),
                ],
                sourceType: 'invoice',
                sourceId: $invoice->id,
                // char(26): the row id is already the source_id, so this key only has to separate
                // this document's opening entry from any later one written for it.
                sourceEventId: 'opening-'.substr(sha1($sourceUuid), 0, 18),
                createdBy: $actorId,
            ));

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $invoice->id,
                fromState: null,
                toState: InvoiceLifecycle::Issued->value,
                reason: "opening receivable imported for legacy document {$reference}",
                context: [
                    'number' => $number,
                    'customer_id' => $customer->id,
                    'currency' => $currency,
                    'amount' => $amountMinor,
                    'base_amount' => $baseMinor,
                    'due_on' => $payableBy->toDateString(),
                ],
                actorId: $actorId,
            );

            return $invoice->refresh();
        }, 3);
    }

    /**
     * @param  array<string, ChartAccount>  $accounts
     */
    private function account(array $accounts, string $code): ChartAccount
    {
        return $accounts[$code] ?? throw new InvalidArgumentException(sprintf(
            'Account %s is not in this company\'s chart, so an opening balance has nowhere to be posted.',
            $code,
        ));
    }
}
