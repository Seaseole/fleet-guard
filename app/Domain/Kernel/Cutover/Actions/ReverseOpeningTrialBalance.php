<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\BatchPostingLines;
use App\Domain\Kernel\Ledger\Actions\PostOpeningBalance;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Phase 0.5's rollback — the rehearsal's "undo", done as the ledger allows it.
 *
 * The posted entry is never edited or deleted. The mirror posts at today's date with
 * `reverses_entry_id` and the batch's `netting_group`, so a report can net the pair and a person can
 * see both what was written and who took it back. The reason is mandatory: the operational-events
 * CHECK refuses a `reversed` transition without one.
 */
final class ReverseOpeningTrialBalance
{
    public function __construct(
        private readonly PostOpeningBalance $openingBalances,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(CutoverBatch $batch, string $reason, int $userId): CutoverBatch
    {
        if ($batch->status !== CutoverBatchStatus::Posted || $batch->journal_entry_id === null) {
            throw CutoverRejected::notPosted($batch->status->value);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CutoverRejected::reasonRequired();
        }

        $rows = $batch->rows()->where('status', 'valid')->get();

        $entry = $this->openingBalances->reverse(
            description: "Reversal of opening balances — {$reason}",
            postedOn: CarbonImmutable::now(CurrentCompany::model()->timezone)->startOfDay(),
            lines: BatchPostingLines::fromRows($rows),
            sourceId: $batch->public_id,
            sourceEventId: $batch->reversal_event_id,
            userId: $userId,
            reversesEntryId: $batch->journal_entry_id,
            nettingGroup: $batch->public_id,
        );

        $batch->forceFill([
            'status' => CutoverBatchStatus::Reversed,
            'reversal_entry_id' => $entry->id,
            'reversed_at' => Carbon::now(),
            'reversed_by' => $userId,
        ])->save();

        $this->audit->operational(
            'cutover_batch',
            $batch->public_id,
            CutoverBatchStatus::Posted->value,
            CutoverBatchStatus::Reversed->value,
            $reason,
            [
                'journal_entry_id' => $batch->journal_entry_id,
                'reversal_entry_id' => $entry->id,
                'reversal_entry_no' => $entry->entry_no,
            ],
            actorId: $userId,
        );

        return $batch->refresh();
    }
}
