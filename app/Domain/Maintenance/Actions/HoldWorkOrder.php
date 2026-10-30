<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Maintenance\HoldReason;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\WorkOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * §10.6's `in_progress → on_hold`, with one of §4.3's four reasons.
 *
 * The reason is an enum rather than free text so "how many jobs are waiting on parts" is a group-by
 * and not a search, and the note beside it is capped because it is the sentence a person reads next
 * to the job, not a file.
 *
 * The reason and the state are written in one UPDATE: `work_orders_hold_is_explained` says a held
 * order has a reason and a running one does not, so a two-step write would leave a row that is briefly
 * on hold with nothing explaining why — and a failed second step would leave it there for good.
 */
final class HoldWorkOrder
{
    public function __invoke(
        WorkOrder $workOrder,
        HoldReason $reason,
        ?string $note = null,
        ?int $actorId = null,
    ): WorkOrder {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Putting a job on hold is a workshop act (§9.3).');
        }

        $note = $note === null ? null : trim($note);

        if ($note === '') {
            $note = null;
        }

        if ($note !== null && mb_strlen($note) > 160) {
            throw new InvalidArgumentException('Keep the hold note to 160 characters — the reason enum carries the category.');
        }

        return DB::transaction(function () use ($workOrder, $reason, $note, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::InProgress) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. Only work under way can be stopped (§10.6).',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            // Dirty attributes, not a save of their own: `moveTo` writes them with the state in one
            // UPDATE, which is what the CHECK needs — a saved reason on a still-running job is the
            // row the constraint exists to refuse.
            $locked->hold_reason = $reason;
            $locked->hold_note = $note;

            $locked->moveTo(
                WorkOrderStatus::OnHold,
                $reason->label().($note === null ? '' : ' — '.$note),
                ['hold_reason' => $reason->value],
                ActorKind::User,
                $actorId,
            );

            return $locked->refresh();
        });
    }
}
