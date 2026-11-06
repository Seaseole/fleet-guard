<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The fiscal act for a hand-raised document: the number is spent, the VAT is computed, the ledger is
 * posted, and the draft stops being one (the `InvoiceIssued`).
 *
 * This is the second half of {@see CreateManualInvoice}, and the split is the whole point — a draft
 * has no number and no entry, so nothing about it can be argued with in the books. Everything that
 * makes this a **document** happens here, in one transaction:
 *
 * 1. **The number is allocated now**, from the same gap-free `invoice` sequence the trip and
 *    consolidated runs draw on. One register, one order, regardless of which door the paper came
 *    from — a separate manual sequence is how a fiscal audit loses the thread.
 * 2. **VAT is computed at the rate effective today** (Q4) and written onto each line, so
 *    the line row says the tax it actually posted with. A draft that is issued in December carries
 *    December's percentage even if it was typed in November.
 * 3. **The posting is the shape the design names**: Dr `1150` for the gross in base currency, Cr `4900`
 *    Other income for the net, Cr `2125` Output VAT for the tax. The receivable is debited with
 *    `balance_base`, never with the document figure — the ledger does not learn a currency it did
 *    not freeze at write time.
 * 4. **The lines keep the base amounts they were drafted with**. Issuing never re-reads a
 *    rate; if the money is worth something different today, that is a new document or a credit note.
 *
 * A manual invoice bills no chargeable, so there is nothing to claim in Operations and nothing to
 * release if this rolls back — the trip's `ready` state is untouched by this door.
 */
final class IssueManualInvoice
{
    private const RECEIVABLE_ACCOUNT = '1150';

    private const OUTPUT_VAT_ACCOUNT = '2125';

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, ?int $actorId = null): Invoice
    {
        if (! Gate::allows('invoices.issue')) {
            throw new InvalidArgumentException('Issuing an invoice needs the invoices.issue ability.');
        }

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $issuedOn = Carbon::now()->startOfDay();

        return DB::transaction(function () use ($invoice, $actorId, $baseCurrency, $issuedOn): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->kind !== InvoiceKind::Manual) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is a %s document; a billing run or a trip issue owns that one, because its lines come '
                    .'from chargeables this Action cannot claim.',
                    $locked->reference(),
                    mb_strtolower($locked->kind->label()),
                ));
            }

            if ($locked->status !== InvoiceLifecycle::Draft) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is %s, and only a draft is issued — an issued document is corrected by a credit note '
                    .'or a void, never re-posted.',
                    $locked->reference(),
                    mb_strtolower($locked->status->label()),
                ));
            }

            if ($locked->currency_code !== $baseCurrency && $locked->base_currency_code !== $baseCurrency) {
                throw new InvalidArgumentException(sprintf(
                    'This draft froze its base in %s, but the company now books in %s. Void the draft and raise it '
                    .'again rather than let one document answer to two base currencies.',
                    $locked->base_currency_code,
                    $baseCurrency,
                ));
            }

            $locked->loadMissing('customer');

            /** @var Collection<int, InvoiceLine> $docLines */
            $docLines = InvoiceLine::query()
                ->where('invoice_id', $locked->id)
                ->orderBy('position')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($docLines->isEmpty()) {
                throw new InvalidArgumentException('A draft with no lines cannot be issued: there is nothing for the journal to post.');
            }

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

            $terms = PaymentTerms::normalise($locked->terms_snapshot);

            if (! PaymentTerms::isValid($terms)) {
                throw new InvalidArgumentException(sprintf(
                    'This draft carries payment terms "%s", which the ageing axis cannot read. Set cash or net_<days> '
                    .'on the customer and redraft.',
                    $locked->terms_snapshot,
                ));
            }

            $number = $this->numbers->allocate('invoice', $issuedOn, $actorId);

            $netMinor = 0;
            $taxMinor = 0;
            $baseNetMinor = 0;
            $baseTaxMinor = 0;

            /** @var array<string, int> $revenueByAccount code => base minor units credited */
            $revenueByAccount = [];

            foreach ($docLines as $line) {
                $lineTax = $rate->taxOn((int) $line->amount);
                $lineTaxBase = $rate->taxOnBase((int) $line->base_amount);

                $line->forceFill([
                    'tax_rate_id' => $line->tax_rate_id ?? $rate->id,
                    'tax_kind' => $line->tax_kind ?? $rate->kind->value,
                    'tax_amount' => $lineTax,
                    'tax_base' => $lineTaxBase,
                ])->save();

                $netMinor += (int) $line->amount;
                $taxMinor += $lineTax;
                $baseNetMinor += (int) $line->base_amount;
                $baseTaxMinor += $lineTaxBase;

                $code = (string) $line->revenue_account_code;
                $revenueByAccount[$code] = ($revenueByAccount[$code] ?? 0) + (int) $line->base_amount;
            }

            // The draft's header is a cache of its lines; if a line was written around this Action,
            // the lines are the truth and the header follows them (the projection rule).
            $locked->forceFill([
                'number' => $number,
                'status' => InvoiceLifecycle::Issued->value,
                'issue_date' => $issuedOn->toDateString(),
                'due_on' => PaymentTerms::dueOn($terms, $issuedOn)->toDateString(),
                'amount' => $netMinor,
                'base_amount' => $baseNetMinor,
                'tax_amount' => $taxMinor,
                'tax_base' => $baseTaxMinor,
                'balance_amount' => $netMinor + $taxMinor,
                'balance_base' => $baseNetMinor + $baseTaxMinor,
                'issued_by' => $actorId,
                'issued_at' => Carbon::now(),
            ])->save();

            $dimensions = array_filter([
                'customer_id' => $locked->customer_id,
                'invoice_id' => $locked->id,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $lines = [
                new LineDto(
                    accountId: $receivable->id,
                    side: Side::Debit,
                    base: Money::ofMinor($baseNetMinor + $baseTaxMinor, $baseCurrency),
                    note: $locked->reference(),
                    dimensions: $dimensions,
                ),
            ];

            foreach ($revenueByAccount as $code => $baseMinor) {
                $account = $accounts[$code] ?? null;

                if ($account === null) {
                    throw new InvalidArgumentException(sprintf('Account %s is not in this company\'s chart, so a manual line cannot be posted.', $code));
                }

                $lines[] = new LineDto(
                    accountId: $account->id,
                    side: Side::Credit,
                    base: Money::ofMinor($baseMinor, $baseCurrency),
                    note: 'Manual charge',
                    // 4900 asks for the customer alone; a manual line has no trip or truck to name.
                    dimensions: $dimensions,
                );
            }

            if ($baseTaxMinor > 0) {
                $lines[] = new LineDto(
                    accountId: $outputVat->id,
                    side: Side::Credit,
                    base: Money::ofMinor($baseTaxMinor, $baseCurrency),
                    note: 'Output VAT',
                    dimensions: $dimensions,
                );
            }

            $this->ledger->post(new PostingDto(
                description: sprintf('Manual invoice %s · %s', $number, $locked->customer->name),
                postedOn: $issuedOn,
                currencyCode: $baseCurrency,
                fxRate: '1',
                fxRateSource: FxRateSource::Manual,
                fxRateDate: $issuedOn,
                lines: $lines,
                sourceType: 'invoice',
                sourceId: $locked->id,
                createdBy: $actorId,
            ));

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $locked->id,
                fromState: InvoiceLifecycle::Draft->value,
                toState: InvoiceLifecycle::Issued->value,
                reason: 'manual invoice issued — number spent and revenue posted',
                context: [
                    'number' => $number,
                    'kind' => InvoiceKind::Manual->value,
                    'currency' => $locked->currency_code,
                    'net' => $netMinor,
                    'tax' => $taxMinor,
                    'base_net' => $baseNetMinor,
                    'base_tax' => $baseTaxMinor,
                    'lines' => $docLines->count(),
                ],
                actorId: $actorId,
            );

            return $locked->refresh()->load('lines');
        }, 3);
    }
}
