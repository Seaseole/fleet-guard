<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\CreditNoteLifecycle;
use App\Domain\Invoicing\Data\CreditNoteDto;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\CreditNoteLine;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Kernel\Audit\AuditRecorder;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * / 'S `CreditNoteIssued`: the invoice was wrong, and this is the document that says so.
 *
 * The note is built **line by line** from the invoice's own lines, because a credit has to take revenue back
 * from the account and the leg it was credited to — a lump credit would land in `4900` and leave the freight
 * leg reading money it no longer earned. VAT mirrors each line's stored rate, never today's, so a note
 * raised in March against a December invoice carries December's percentage.
 *
 * Three refusals make the door safe:
 *
 * - a line cannot be credited more than it was billed, and not twice at all (`credit_note_lines` is unique on
 *   the invoice line, because a second credit would drive revenue negative through an unwatched door);
 * - the whole credit cannot exceed the invoice's outstanding balance — a credit larger than what is owed is a
 *   refund, which is phase 5's cash question;
 * - only an `issued` invoice can be corrected by credit. A void one is already unposted, and a draft was never
 *   revenue.
 *
 * The note is issued and **applied in the same transaction**: it allocates itself against the invoice, so the
 * invoice's projected balance falls by the credit's gross and the note's own balance reaches zero. A note left
 * `issued` with a balance would be a correction the ledger has posted but the receivable has not felt.
 */
final class IssueCreditNote
{
    private const OUTPUT_VAT_ACCOUNT = '2125';

    private const RECEIVABLE_ACCOUNT = '1150';

    /**
     * The closed vocabulary. A free-text reason would make the register ungroupable, and the migration's
     * CHECK would refuse it anyway.
     *
     * @var list<string>
     */
    private const REASONS = [
        'over_billed',
        'pricing_error',
        'goods_damaged',
        'service_not_rendered',
        'void_leg',
        'other',
    ];

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
        private readonly NumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, CreditNoteDto $dto, ?int $actorId = null): CreditNote
    {
        if (! Gate::allows('credit_notes.manage')) {
            throw new InvalidArgumentException('Raising a credit note needs the credit_notes.manage ability.');
        }

        if (! in_array($dto->reason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not one of the credit reasons (%s), so the note would not be groupable in the register.',
                $dto->reason,
                implode(', ', self::REASONS),
            ));
        }

        if ($dto->lines === []) {
            throw new InvalidArgumentException('A credit note needs at least one line. There is nothing to correct otherwise.');
        }

        return DB::transaction(function () use ($invoice, $dto, $actorId): CreditNote {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceLifecycle::Issued) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is %s. Only an issued invoice is corrected by a credit note — a void one is already '
                    .'unposted, and a draft was never revenue.',
                    $locked->reference(),
                    mb_strtolower($locked->status->label()),
                ));
            }

            $baseCurrency = CurrentCompany::model()->base_currency_code;

            // A credit note and its ledger lines are base-currency only (see the identity block
            // rule), so the document-currency figures a foreign invoice carries would be filed as
            // if they were already converted.
            if ($locked->currency_code !== $baseCurrency) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is billed in %s and a credit note here is posted in this book\'s %s only, so the correction would move the wrong amount. Void the invoice and re-issue it in the currency the customer was billed in.',
                    $locked->reference(),
                    $locked->currency_code,
                    $baseCurrency,
                ));
            }

            $invoiceLines = $locked->lines()->get()->keyBy('id');
            $alreadyCredited = CreditNoteLine::query()
                ->whereIn('invoice_line_id', array_keys($dto->lines))
                ->pluck('invoice_line_id')
                ->all();

            $rows = [];

            foreach ($dto->lines as $invoiceLineId => $amountMinor) {
                $line = $invoiceLines->get($invoiceLineId);

                if (! $line instanceof InvoiceLine) {
                    throw new InvalidArgumentException('That line is not on this invoice, so a credit naming it would take money from a document it never billed.');
                }

                if (in_array($invoiceLineId, $alreadyCredited, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'The line "%s" has already been credited. A second credit of one billed line would drive its '
                        .'revenue negative.',
                        $line->description,
                    ));
                }

                if ($amountMinor <= 0 || $amountMinor > $line->amount) {
                    throw new InvalidArgumentException(sprintf(
                        'A credit of %d against "%s" is outside what it was billed (%d). Credit a positive part of the '
                        .'line, not more than it earned.',
                        $amountMinor,
                        $line->description,
                        $line->amount,
                    ));
                }

                $rate = $line->tax_rate_id === null ? null : TaxRate::query()->find($line->tax_rate_id);
                $tax = $rate?->taxOn($amountMinor) ?? 0;

                $rows[] = [
                    'line' => $line,
                    'amount' => $amountMinor,
                    'tax' => $tax,
                    'rate' => $rate,
                ];
            }

            $net = array_sum(array_column($rows, 'amount'));
            $tax = array_sum(array_column($rows, 'tax'));
            $gross = $net + $tax;

            if ($gross > $locked->balance_amount) {
                throw new InvalidArgumentException(sprintf(
                    'The credit is %d and invoice %s still owes %d. A credit larger than the balance is a refund, '
                    .'which is a cash decision, not a correction.',
                    $gross,
                    $locked->reference(),
                    $locked->balance_amount,
                ));
            }

            $accounts = $this->chart->byCode();
            $receivable = $accounts[self::RECEIVABLE_ACCOUNT] ?? null;
            $outputVat = $accounts[self::OUTPUT_VAT_ACCOUNT] ?? null;

            if ($receivable === null || $outputVat === null) {
                throw new InvalidArgumentException('Accounts 1150 and 2125 must both be in the chart for a credit note to post.');
            }

            $issuedOn = Carbon::now(CurrentCompany::timezone())->startOfDay();
            $number = $this->numbers->allocate('credit_note', $issuedOn, $actorId);

            $note = CreditNote::create([
                'id' => (string) Str::ulid(),
                'number' => $number,
                'status' => CreditNoteLifecycle::Issued->value,
                'customer_id' => $locked->customer_id,
                'invoice_id' => $locked->id,
                'issue_date' => $issuedOn->toDateString(),
                'reason' => $dto->reason,
                // Base currency only, as with issuing: the identity block, not a conversion.
                'currency_code' => $baseCurrency,
                'amount' => $net,
                'fx_rate' => '1',
                'fx_rate_source' => FxRateSource::Manual->value,
                'fx_rate_date' => $issuedOn->toDateString(),
                'base_currency_code' => $baseCurrency,
                'base_amount' => $net,
                'tax_amount' => $tax,
                'tax_base' => $tax,
                'balance_amount' => $gross,
                'balance_base' => $gross,
                'narrative' => $dto->narrative === null || trim($dto->narrative) === '' ? null : trim($dto->narrative),
                'issued_by' => $actorId,
                'issued_at' => Carbon::now(),
            ]);

            $ledgerLines = [];

            foreach ($rows as $index => $row) {
                /** @var InvoiceLine $line */
                $line = $row['line'];
                /** @var TaxRate|null $rate */
                $rate = $row['rate'];

                CreditNoteLine::create([
                    'id' => (string) Str::ulid(),
                    'credit_note_id' => $note->id,
                    'invoice_line_id' => $line->id,
                    'description' => mb_substr($line->description, 0, 160),
                    'line_kind' => $line->line_kind->value,
                    // Copied, not joined: the credit must return to the leg the revenue came from.
                    'trip_id' => $line->trip_id,
                    'job_id' => $line->job_id,
                    'truck_id' => $line->truck_id,
                    'revenue_account_code' => $line->revenue_account_code,
                    'currency_code' => $baseCurrency,
                    'amount' => $row['amount'],
                    'base_amount' => $row['amount'],
                    'tax_rate_id' => $rate?->id,
                    'tax_kind' => $rate?->kind->value,
                    'tax_amount' => $row['tax'],
                    'tax_base' => $row['tax'],
                    'position' => $index + 1,
                ]);

                $account = $accounts[$line->revenue_account_code] ?? null;

                if ($account === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Account %s is not in the chart, so the credited revenue cannot be reversed.',
                        $line->revenue_account_code,
                    ));
                }

                $ledgerLines[] = new LineDto(
                    accountId: $account->id,
                    side: Side::Debit,
                    base: Money::ofMinor($row['amount'], $baseCurrency),
                    note: mb_substr($line->description, 0, 255),
                    dimensions: array_filter([
                        'customer_id' => $locked->customer_id,
                        'invoice_id' => $locked->id,
                        'trip_id' => $line->trip_id,
                        'truck_id' => $line->truck_id,
                        'job_id' => $line->job_id,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );
            }

            if ($tax > 0) {
                $ledgerLines[] = new LineDto(
                    accountId: $outputVat->id,
                    side: Side::Debit,
                    base: Money::ofMinor($tax, $baseCurrency),
                    note: 'Output VAT credited',
                    dimensions: array_filter([
                        'customer_id' => $locked->customer_id,
                        'invoice_id' => $locked->id,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );
            }

            $ledgerLines[] = new LineDto(
                accountId: $receivable->id,
                side: Side::Credit,
                base: Money::ofMinor($gross, $baseCurrency),
                note: $number,
                dimensions: array_filter([
                    'customer_id' => $locked->customer_id,
                    'invoice_id' => $locked->id,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            );

            $this->ledger->post(new PostingDto(
                description: sprintf('Credit note %s against %s', $number, $locked->reference()),
                postedOn: $issuedOn,
                currencyCode: $baseCurrency,
                fxRate: '1',
                fxRateSource: FxRateSource::Manual,
                fxRateDate: $issuedOn,
                lines: $ledgerLines,
                sourceType: 'credit_note',
                sourceId: $note->id,
                createdBy: $actorId,
            ));

            // Applied in the same stroke: the note's whole balance is allocated against the invoice it
            // corrects, so nothing is left owing on a correction the ledger has already posted.
            $note->forceFill([
                'status' => CreditNoteLifecycle::Applied->value,
                'balance_amount' => 0,
                'balance_base' => 0,
            ])->save();

            $locked->forceFill([
                'balance_amount' => $locked->balance_amount - $gross,
                'balance_base' => $locked->balance_base - $gross,
            ])->save();

            $this->audit->operational(
                aggregateType: 'credit_note',
                aggregateId: $note->id,
                fromState: CreditNoteLifecycle::Issued->value,
                toState: CreditNoteLifecycle::Applied->value,
                reason: $dto->narrative,
                context: [
                    'number' => $number,
                    'invoice_id' => $locked->id,
                    'reason' => $dto->reason,
                    'amount' => $net,
                    'tax' => $tax,
                ],
                actorId: $actorId,
            );

            return $note->refresh()->load('lines');
        }, 3);
    }
}
