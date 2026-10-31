<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\BillLinkedSource;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Fuel\Actions\MarkDeliveryBilled;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Actions\ReversePosting;
use App\Domain\Maintenance\Actions\MarkStockReceiptBilled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * §10.9 / §6.4 — a recorded bill that should not have been recorded is **voided**, never deleted.
 *
 * The bill keeps its `BILL-` number (operational references are never reused, §7), the posting is
 * mirrored through the ledger's own {@see ReversePosting} door with `reverses_entry_id` so the pair
 * nets to zero and both halves stay readable, and the lines are stamped `released_at` rather than
 * deleted — the document keeps its own record even when the money is unposted.
 *
 * Two slice-5b rules sit on top of that shape:
 *
 * 1. **A bill with money against it is not voided.** The moment a standing application exists, the
 *    cash has left the till and the payable is settled — voiding would unpost a liability the bank
 *    has already discharged. This is §20.33's decision 6 read from the other side of the ledger; the
 *    correction door for a paid bill is §4.2's debit note, which is a later slice.
 * 2. **A linked bill has nothing to mirror.** Its obligation was posted by Phase 3's movement (§20.36),
 *    so voiding releases the stamp through the owning module's own door and the delivery or receipt
 *    returns to "awaiting the bill" — the paper can then be recorded again on a fresh number.
 */
final class VoidBill
{
    public function __construct(
        private readonly ReversePosting $reversePosting,
        private readonly AuditRecorder $audit,
        private readonly MarkDeliveryBilled $stampDelivery,
        private readonly MarkStockReceiptBilled $stampReceipt,
    ) {}

    public function __invoke(Bill $bill, string $reason, ?int $actorId = null): Bill
    {
        if (! Gate::allows('bills.create')) {
            throw new InvalidArgumentException('Voiding a supplier bill unposts a ledger entry; it needs the bills.create ability (§9.3).');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException(
                'A void needs a reason of at least ten characters. It is stored on the document and in the '
                .'operational trail, because "why" is the only part of a void a later reader cannot reconstruct (§10.1).',
            );
        }

        return DB::transaction(function () use ($bill, $reason, $actorId): Bill {
            /** @var Bill $locked */
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BillLifecycle::Recorded) {
                throw new InvalidArgumentException(sprintf(
                    'Bill %s is %s, and only a recorded bill has a posting to mirror — a draft never posted anything.',
                    $locked->number,
                    mb_strtolower($locked->status->label()),
                ));
            }

            if ($locked->hasPayments()) {
                throw new InvalidArgumentException(sprintf(
                    'Bill %s has a supplier payment against it, so it cannot be voided — the cash has left the till and the payable is settled. A paid bill is corrected by a debit note, not by unposting the debt it already paid (§20.33\'s rule, read from the other side of the ledger).',
                    $locked->number,
                ));
            }

            if ($locked->linked_source === null) {
                ($this->reversePosting)(
                    originalSourceType: 'bill',
                    originalSourceId: $locked->id,
                    sourceType: 'bill_void',
                    sourceId: $locked->id,
                    // char(26): the bill's own ULID is the widest id-shaped group that fits.
                    nettingGroup: $locked->id,
                    description: sprintf('Void %s · %s', $locked->number, $reason),
                    actorId: $actorId,
                );
            } else {
                // A linked bill posted nothing of its own (§20.36), so there is no entry to mirror:
                // the obligation is released through the owning module's door and the delivery or
                // receipt returns to "awaiting the bill", offerable to the next paper.
                $companyId = (int) $locked->company_id;
                $obligationId = (string) $locked->linked_source_id;

                match ($locked->linked_source) {
                    BillLinkedSource::FuelDelivery => ($this->stampDelivery)($obligationId, null, $companyId),
                    BillLinkedSource::StockReceipt => ($this->stampReceipt)($obligationId, null, $companyId),
                };
            }

            $locked->lines()->whereNull('released_at')->update(['released_at' => now()]);

            $locked->forceFill([
                'status' => BillLifecycle::Void->value,
                'void_reason' => mb_substr($reason, 0, 300),
                'voided_at' => now(),
                'voided_by' => $actorId,
                // The voided bill stops claiming the supplier document, so the same paper can be
                // recorded again on a fresh row; the unique key allows many NULLs but one value.
                'source_uuid' => null,
            ])->save();

            $this->audit->operational(
                aggregateType: 'bill',
                aggregateId: $locked->id,
                fromState: BillLifecycle::Recorded->value,
                toState: BillLifecycle::Void->value,
                reason: $reason,
                context: [
                    'number' => $locked->number,
                    'supplier_id' => $locked->supplier_id,
                    'gross' => $locked->amount,
                ],
                actorId: $actorId,
            );

            return $locked->refresh();
        }, 3);
    }
}
