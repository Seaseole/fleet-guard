<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Actions\ReversePosting;
use App\Domain\Operations\Actions\ReleaseTripCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * / An issued invoice that should never have existed is **voided**, never deleted.
 *
 * Three rules, and the first is the phase's acceptance test:
 *
 * 1. **A void is refused once a payment exists.** If any allocation still stands against this invoice, the
 *    money is real and applied, so the answer to "this invoice was wrong" is a credit note, not a
 *    void. The refund path is deliberately not a silent void: it would unpost a receivable against which a
 *    bank credit already sits.
 * 2. **The number stays spent.** The row keeps its number and flips to `void`; The gap-free promise is
 *    about numbers never being reused, and renumbering a fiscal document is exactly what it forbids. The
 *    domain keeps the reason (the CHECK refuses one shorter than ten characters).
 * 3. **The posting is mirrored, not edited**: {@see ReversePosting} writes the opposite entry with
 *    `reverses_entry_id`, so the pair nets to zero and both halves remain readable.
 *
 * The delivery is **released** back to `ready` through Operations' own door, because a voided invoice must
 * not leave a charge claimed forever — the trip becomes billable again in the same transaction that
 * unposts the revenue.
 */
final class VoidInvoice
{
    public function __construct(
        private readonly ReversePosting $reversePosting,
        private readonly ReleaseTripCharges $releaseCharges,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Invoice $invoice, string $reason, ?int $actorId = null): Invoice
    {
        if (! Gate::allows('invoices.void')) {
            throw new InvalidArgumentException('Voiding an invoice needs the invoices.void ability.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException(
                'A void needs a reason of at least ten characters. It is stored on the document and in the '
                .'operational trail, because "why" is the only part of a void a later reader cannot reconstruct.',
            );
        }

        return DB::transaction(function () use ($invoice, $reason, $actorId): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceLifecycle::Issued) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s is %s, and only an issued invoice can be voided.',
                    $locked->reference(),
                    mb_strtolower($locked->status->label()),
                ));
            }

            $applied = $locked->allocations()->where('reversed', false)->exists();

            if ($applied) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s has a payment allocated to it, so it cannot be voided — the money is real and already '
                    .'applied. Raise a credit note instead.',
                    $locked->reference(),
                ));
            }

            ($this->reversePosting)(
                originalSourceType: 'invoice',
                originalSourceId: $locked->id,
                sourceType: 'invoice_void',
                sourceId: $locked->id,
                // `char(26)`: the netting group is an id-shaped value, so the invoice's own ULID is the
                // widest group that fits, and the source columns already say which document it belongs to.
                nettingGroup: $locked->id,
                description: sprintf('Void %s · %s', $locked->number ?? $locked->id, $reason),
                actorId: $actorId,
            );

            // The lines are released, not deleted: the document keeps its own record, and releasing them is
            // what lets the same chargeable fund the replacement invoice.
            $locked->lines()->whereNull('released_at')->update(['released_at' => now()]);

            // The delivery is billable again — through Operations' door, under its own lock (D5).
            $chargeableIds = $locked->lines()->pluck('chargeable_id')->filter()->values()->all();
            $tripIds = $locked->lines()->pluck('trip_id')->filter()->unique()->values()->all();

            /** @var list<string> $idsToRelease */
            $idsToRelease = array_values(array_map(static fn (mixed $id): string => (string) $id, $chargeableIds));

            foreach ($tripIds as $tripId) {
                ($this->releaseCharges)((string) $tripId, $idsToRelease);
            }

            $locked->forceFill([
                'status' => InvoiceLifecycle::Void->value,
                'void_reason' => mb_substr($reason, 0, 300),
                'voided_at' => now(),
                'voided_by' => $actorId,
                // The voided document stops claiming the trip, so the delivery can be billed on a new
                // document. `invoices_company_source_uuid_unique` allows many NULLs but only one row per
                // trip, and a void must not make a trip permanently unbillable.
                'source_uuid' => null,
            ])->save();

            $this->audit->operational(
                aggregateType: 'invoice',
                aggregateId: $locked->id,
                fromState: InvoiceLifecycle::Issued->value,
                toState: InvoiceLifecycle::Void->value,
                reason: $reason,
                context: [
                    'number' => $locked->number,
                    'released_chargeables' => $chargeableIds,
                ],
                actorId: $actorId,
            );

            return $locked->refresh();
        }, 3);
    }
}
