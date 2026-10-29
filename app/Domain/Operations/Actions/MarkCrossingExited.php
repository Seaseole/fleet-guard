<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Models\BorderCrossing;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * `entered → exited` (§10.9): the truck is through, and the charges become claimable.
 *
 * The exit cannot precede the entry — the CHECK on the table says so, and this Action says it in a
 * sentence first, because "the truck left at 09:00 and arrived at 07:00" is a data-entry mistake the
 * clerk can fix and a constraint violation is not. `clearance_state` is free text on purpose: the
 * gate's own word ("cleared", "held for scan") is evidence, and a fixed vocabulary invented here
 * would be a vocabulary the border does not use.
 *
 * Nothing is posted from this edge. The costs post as they are captured, one journal entry each
 * (§6.6); `FinishBorderCosts` is what ends the crossing, and Phase 6's reconciliation reads the
 * `exited` moment to date the agent's float.
 */
final class MarkCrossingExited
{
    public function __invoke(
        BorderCrossing $crossing,
        ?string $clearanceState = null,
        ?int $actorId = null,
        ?CarbonInterface $at = null,
    ): BorderCrossing {
        if (! Gate::allows('crossborder.manage')) {
            throw new InvalidArgumentException('Recording a gate exit is cross-border management (§9.3).');
        }

        if ($crossing->status !== BorderCrossingStatus::Entered) {
            throw new InvalidArgumentException(sprintf(
                'The crossing at %s is %s. A gate is exited only after it was entered.',
                $crossing->label(),
                $crossing->status->label(),
            ));
        }

        $moment = $at === null ? Carbon::now() : Carbon::instance($at);

        if ($crossing->entered_at !== null && $moment->lt($crossing->entered_at)) {
            throw new InvalidArgumentException(sprintf(
                'The truck cannot leave %s at %s — it had not entered until %s.',
                $crossing->label(),
                $moment->format('d M Y H:i'),
                $crossing->entered_at->format('d M Y H:i'),
            ));
        }

        $state = $clearanceState === null ? null : trim($clearanceState);

        if ($state !== null && mb_strlen($state) > 40) {
            throw new InvalidArgumentException('The clearance state is at most 40 characters — it is the gate\'s word, not a paragraph.');
        }

        return DB::transaction(function () use ($crossing, $state, $actorId, $moment): BorderCrossing {
            $crossing->exited_at = $moment;

            if ($state !== null && $state !== '') {
                $crossing->clearance_state = $state;
            }

            $crossing->moveTo(
                BorderCrossingStatus::Exited,
                null,
                ['exited_at' => $moment->toIso8601String()],
                ActorKind::User,
                $actorId,
            );

            return $crossing->refresh();
        });
    }
}
