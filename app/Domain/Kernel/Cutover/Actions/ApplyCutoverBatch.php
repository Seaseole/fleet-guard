<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverHandlers;
use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 — turn a validated master-data batch into records, once.
 *
 * The module handler writes through its own Actions; this Action owns the surrounding transaction
 * and the batch's transition, so "all the rows applied" and "the batch says applied" commit
 * together. A failure rolls both back and leaves the batch `validated`, which is what makes a
 * retry safe: nothing half-applied is left to reconcile.
 *
 * Applying is the Owner's second half of the cutover workflow, next to committing the opening
 * balances. Master rows are corrected on their own screens afterwards; this Action offers no
 * reversal, because a rollback of thousands of upserts is not a mirror entry.
 */
final class ApplyCutoverBatch
{
    public function __construct(
        private readonly CutoverHandlers $handlers,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(CutoverBatch $batch, int $userId): CutoverBatch
    {
        $from = $batch->status;

        if (! $batch->dataset->isMasterData()) {
            throw CutoverRejected::notMasterData($batch->dataset->value);
        }

        if (! $batch->isReadyToApply()) {
            throw CutoverRejected::notReady($from->value);
        }

        $handler = $this->handlers->for($batch->dataset);

        $applied = DB::transaction(function () use ($batch, $handler, $userId): int {
            $count = $handler->apply($batch, $userId);

            $batch->forceFill([
                'status' => CutoverBatchStatus::Applied,
                'applied_at' => Carbon::now(),
                'applied_by' => $userId,
            ])->save();

            return $count;
        });

        $this->audit->operational(
            'cutover_batch',
            $batch->public_id,
            $from->value,
            CutoverBatchStatus::Applied->value,
            null,
            [
                'dataset' => $batch->dataset->value,
                'rows' => $batch->row_count,
                'applied' => $applied,
            ],
            actorId: $userId,
        );

        return $batch->refresh();
    }
}
