<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A draft that should not have been raised is discarded, and this is the only door that deletes an
 * invoice.
 *
 * The temptation is a `void` status, and the schema refuses it: `invoices_number_only_when_issued`
 * demands a number on anything that is not a draft, and spending a fiscal number on a document that
 * was never issued is precisely the gap exists to prevent. So the row cannot be *voided* — and
 * it does not need to be, because a draft has no posting, no number and no receivable. There is
 * nothing in the books to un-post, and an empty row that says nothing is worse evidence than no row.
 *
 * Three guards, all of them about the same fear — that someone uses this door on something real:
 *
 * 1. **Only a draft.** An issued invoice is corrected by a credit note or voided by
 *    {@see VoidInvoice}, which mirrors the posting instead of deleting it.
 * 2. **Only a manual document.** A trip or consolidated draft would leave its chargeables claimed
 *    forever, because release is `VoidInvoice`'s job and this Action does not touch Operations.
 * 3. **Nothing applied to it.** A draft should have no allocations; if one exists the row is a
 *    ledger fact and the screen must not be able to erase the evidence.
 *
 * The audit trail is written **before** the delete, with the reason and the figures the row carried.
 * The trail outlives the document — that is the order the design asks for, since "why" is the part nobody
 * can reconstruct afterwards.
 */
final class DiscardManualInvoice
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, string $reason, ?int $actorId = null): void
    {
        if (! Gate::allows('invoices.draft')) {
            throw new InvalidArgumentException('Discarding a draft needs the invoices.draft ability.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException(
                'A discard needs a reason of at least ten characters. The row disappears; the sentence in the '
                .'operational trail is all that will explain it.',
            );
        }

        DB::transaction(function () use ($invoice, $reason, $actorId): void {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->kind !== InvoiceKind::Manual) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is a %s document. Its lines are billed chargeables; a void releases them through '
                    .'Operations, a delete would strand them.',
                    $locked->reference(),
                    mb_strtolower($locked->kind->label()),
                ));
            }

            if ($locked->status !== InvoiceLifecycle::Draft) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is %s and carries a number, so it is a fiscal document. Void it or credit it — never '
                    .'delete it.',
                    $locked->reference(),
                    mb_strtolower($locked->status->label()),
                ));
            }

            if ($locked->allocations()->where('reversed', false)->exists()) {
                throw new InvalidArgumentException(sprintf(
                    'Money has been applied to %s, so it is in the books and cannot be discarded — the allocation is '
                    .'evidence.',
                    $locked->reference(),
                ));
            }

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $locked->id,
                fromState: InvoiceLifecycle::Draft->value,
                toState: 'discarded',
                reason: $reason,
                context: [
                    'kind' => InvoiceKind::Manual->value,
                    'customer_id' => $locked->customer_id,
                    'currency' => $locked->currency_code,
                    'net' => (int) $locked->amount,
                    'base_net' => (int) $locked->base_amount,
                    'lines' => $locked->lines()->count(),
                    'discarded_by' => $actorId,
                ],
                actorId: $actorId,
            );

            // The lines go with it: `invoice_lines_invoice_fk` is `on delete cascade`, and a manual
            // line names no chargeable, so nothing outside this document's own history is touched.
            $locked->delete();
        }, 3);
    }
}
