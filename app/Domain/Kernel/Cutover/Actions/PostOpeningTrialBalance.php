<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\BatchPostingLines;
use App\Domain\Kernel\Ledger\Actions\PostOpeningBalance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Phase 0.5 — commit a validated opening trial balance to the ledger as one real journal.
 *
 * The posting goes through {@see PostOpeningBalance} inside the Ledger (D3), carrying the batch's
 * `public_id` and a `posting_event_id` minted at import. A commit interrupted after the entry posts
 * but before the batch records it is retried with the same triple; the ledger's idempotency index
 * returns the existing entry instead of posting the balances twice.
 *
 * `posted_on` is the Owner's choice — the fiscal-year start in the normal case — and the ledger
 * refuses a locked period. A failed post leaves the batch `validated`, so the screen can offer a
 * different date without re-uploading the file.
 */
final class PostOpeningTrialBalance
{
    public function __construct(
        private readonly PostOpeningBalance $openingBalances,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(CutoverBatch $batch, CarbonImmutable $postedOn, int $userId): CutoverBatch
    {
        $from = $batch->status;

        // The reverse of the guard in ApplyCutoverBatch: a master file never becomes a journal,
        // even if a caller reaches this Action with a validated batch in hand.
        if ($batch->dataset->isMasterData()) {
            throw CutoverRejected::notOpeningBalances($batch->dataset->value);
        }

        if ($from === CutoverBatchStatus::Posted || $from === CutoverBatchStatus::Reversed) {
            throw CutoverRejected::alreadyPosted($from->value);
        }

        if (! $batch->isReadyToPost()) {
            throw CutoverRejected::notReady($from->value);
        }

        $rows = $batch->rows()->where('status', 'valid')->get();

        if ($rows->isEmpty()) {
            throw CutoverRejected::notReady($from->value);
        }

        $postedOn = $postedOn->startOfDay();

        $entry = $this->openingBalances->post(
            description: sprintf(
                'Opening balances — %s (batch #%d)',
                $batch->original_filename ?? 'pasted file',
                $batch->id,
            ),
            postedOn: $postedOn,
            lines: BatchPostingLines::fromRows($rows),
            sourceId: $batch->public_id,
            sourceEventId: $batch->posting_event_id,
            userId: $userId,
        );

        $batch->forceFill([
            'status' => CutoverBatchStatus::Posted,
            'journal_entry_id' => $entry->id,
            'posted_on' => $postedOn->toDateString(),
            'posted_at' => Carbon::now(),
            'posted_by' => $userId,
        ])->save();

        $this->audit->operational(
            'cutover_batch',
            $batch->public_id,
            $from->value,
            CutoverBatchStatus::Posted->value,
            null,
            [
                'journal_entry_id' => $entry->id,
                'entry_no' => $entry->entry_no,
                'total_debit' => $entry->total_debit,
                'posted_on' => $postedOn->toDateString(),
            ],
            actorId: $userId,
        );

        return $batch->refresh();
    }
}
