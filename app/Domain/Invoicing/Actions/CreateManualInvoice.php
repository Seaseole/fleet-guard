<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Data\ManualInvoiceDto;
use App\Domain\Invoicing\Data\ManualInvoiceLineDto;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Commercial\PaymentTerms;
use App\Domain\Kernel\Money\RateResolver;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 4's manual door, opened: a clerk raises a document with no delivery behind it.
 *
 * `InvoiceKind::Manual` and `InvoiceLineKind::Manual` have existed since Phase 0's enums, and the
 * CHECK on `invoices.kind` always allowed the value — what was missing was any Action that could
 * write one. This is that door, and it deliberately stops short of the fiscal act:
 *
 * 1. **A draft spends no number.** `invoices_number_only_when_issued` is the schema saying it: a
 *    draft is addressed by its ULID, and a number spent on a document nobody has committed to is a
 *    gap in a fiscal register. {@see IssueManualInvoice} is where the sequence moves.
 * 2. **A draft posts nothing.** No journal entry, no receivable, nothing on the trial balance —
 *    which is why a draft that turns out to be wrong is discarded rather than corrected (the design has
 *    no mirror to write for something that never existed).
 * 3. **The lines are frozen at draft time, including their base amounts**. A ZAR line typed
 *    today and issued after a rate move still carries today's `base_amount`, because the conversion
 *    was a decision made with the paper in front of the clerk, not a figure to be re-derived from a
 *    feed on the day of posting.
 * 4. **VAT is not computed here.** The rate is the one effective on the *issue* date (Q4's
 *    rule, and the per-line rounding), so a draft shows net figures and the tax arrives with the
 *    fiscal act. A draft's `balance` is therefore provisional by design, and the tie-out command
 *    skips drafts for exactly that reason.
 *
 * Lines are restricted to the kinds whose account asks only for the customer: freight,
 * detention, customs and fuel lines belong to a leg, and a hand-raised document has no leg to name.
 */
final class CreateManualInvoice
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(ManualInvoiceDto $dto, ?int $actorId = null): Invoice
    {
        if (! Gate::allows('invoices.draft')) {
            throw new InvalidArgumentException('Raising a manual invoice needs the invoices.draft ability.');
        }

        $customer = Customer::query()->find($dto->customerId)
            ?? throw new InvalidArgumentException('That customer is not in this company\'s register, so the document has no party to address.');

        if (! (bool) $customer->active) {
            throw new InvalidArgumentException(sprintf(
                '%s is marked inactive. Bill an active customer or reopen the record first — a manual document is '
                .'the one route that does not pass through a job that already named them.',
                $customer->name,
            ));
        }

        $terms = PaymentTerms::normalise($customer->payment_terms);

        if (! PaymentTerms::isValid($terms)) {
            throw new InvalidArgumentException(sprintf(
                'Customer %s has payment terms "%s", which are not a term the ageing axis can read. Set cash or '
                .'net_<days> before drafting.',
                $customer->name,
                $customer->payment_terms,
            ));
        }

        $baseCurrency = CurrentCompany::model()->base_currency_code;
        $draftedOn = Carbon::now()->startOfDay();
        $currency = $dto->currency() === '' ? $baseCurrency : $dto->currency();

        // The desk usually knows the rate — it is on the quote. When it does not, the stored bulletin
        // is what the document converts at, and the row says so in `fx_rate_source` rather than
        // leaving the reader to guess which day's feed was open.
        if ($currency !== $baseCurrency && $dto->rate() === null && $dto->baseAmountMinor === null) {
            $stored = $this->rates->resolve($currency, $draftedOn);

            if ($stored === null) {
                throw new InvalidArgumentException(sprintf(
                    'A %s document needs the rate the desk agreed or the base the charge is worth to this company, and no '
                    .'%s rate has ever been entered in the rate register either.',
                    $currency,
                    $currency,
                ));
            }

            $dto = $dto->withRate($stored->rate(), $stored->source, $stored->ref === null ? null : (string) $stored->ref);
        }

        $bases = $dto->baseAmounts($baseCurrency, $draftedOn);
        $netMinor = $dto->foreignTotalMinor();
        $baseNetMinor = array_sum($bases);

        if ($netMinor <= 0 || $baseNetMinor <= 0) {
            throw new InvalidArgumentException('A manual invoice must ask for a positive amount in both currencies; a zero document posts a zero journal entry.');
        }

        return DB::transaction(function () use ($dto, $actorId, $customer, $terms, $currency, $baseCurrency, $draftedOn, $bases, $netMinor, $baseNetMinor): Invoice {
            $invoice = Invoice::create([
                'id' => (string) Str::ulid(),
                // NULL by rule, not by omission: the CHECK refuses a number on a draft and demands one
                // on anything else, so the lifecycle and the register cannot drift apart.
                'number' => null,
                'status' => InvoiceLifecycle::Draft->value,
                'customer_id' => $customer->id,
                'job_id' => null,
                'kind' => InvoiceKind::Manual->value,
                'issue_date' => $draftedOn->toDateString(),
                'due_on' => PaymentTerms::dueOn($terms, $draftedOn)->toDateString(),
                'terms_snapshot' => $terms,
                'currency_code' => $currency,
                'amount' => $netMinor,
                'fx_rate' => $dto->resolvedRate($baseCurrency),
                'fx_rate_source' => $dto->resolvedSource()->value,
                'fx_rate_date' => $draftedOn->toDateString(),
                'fx_rate_ref' => $dto->fxRateRef,
                'base_currency_code' => $baseCurrency,
                'base_amount' => $baseNetMinor,
                // No VAT yet — the rate is the one effective on the issue date (Q4).
                'tax_amount' => 0,
                'tax_base' => 0,
                'balance_amount' => $netMinor,
                'balance_base' => $baseNetMinor,
                'po_reference' => $dto->poReference,
                'note' => $dto->note,
            ]);

            foreach ($dto->lines as $index => $line) {
                $this->writeLine($invoice, $line, $bases[$index], $currency, $index + 1);
            }

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $invoice->id,
                fromState: null,
                toState: InvoiceLifecycle::Draft->value,
                reason: 'manual invoice drafted by hand',
                context: [
                    'customer_id' => $customer->id,
                    'kind' => InvoiceKind::Manual->value,
                    'currency' => $currency,
                    'net' => $netMinor,
                    'base_net' => $baseNetMinor,
                    'lines' => count($dto->lines),
                ],
                actorId: $actorId,
            );

            return $invoice->refresh()->load('lines');
        }, 3);
    }

    /**
     * One hand-typed line. `unit_price_minor` carries the line's own amount at quantity 100 — the
     * CHECK on the table reads amount × 100 = quantity × unit price, so a manual line is the same
     * shape as a billed one with a single unit behind it.
     */
    private function writeLine(Invoice $invoice, ManualInvoiceLineDto $line, int $baseMinor, string $currency, int $position): void
    {
        InvoiceLine::create([
            'id' => (string) Str::ulid(),
            'invoice_id' => $invoice->id,
            'description' => $line->description,
            'line_kind' => $line->kind->value,
            'chargeable_id' => null,
            'trip_id' => null,
            'job_id' => null,
            'truck_id' => null,
            'revenue_account_code' => $line->kind->accountCode(),
            'quantity_x100' => 100,
            'unit_price_minor' => $line->amountMinor,
            'currency_code' => $currency,
            'amount' => $line->amountMinor,
            'base_amount' => $baseMinor,
            'tax_rate_id' => null,
            'tax_kind' => null,
            'tax_amount' => 0,
            'tax_base' => 0,
            'position' => $position,
        ]);
    }
}
