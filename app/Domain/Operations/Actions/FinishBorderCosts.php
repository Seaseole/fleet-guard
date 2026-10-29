<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Models\BorderCrossing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * `exited → costs_captured` (§10.9): the clerk says the gate is done being charged.
 *
 * The edge is a person's claim, not a derived fact, which is why it exists at all: a crossing whose
 * costs are still arriving must stay open, and a crossing where nothing was paid needs the same
 * closing act as one with four receipts. The one thing it is not allowed to do is close a gate with
 * no charges at all — "exited and nothing paid" is a plausible fact, but it is indistinguishable from
 * "the clerk has not captured the duty yet", and Phase 6's reconciliation would inherit the ambiguity.
 *
 * Phase 6 owns everything past this point (`agent_reconciled`, `closed`); this Action refuses to
 * pretend it has the agent float to check.
 */
final class FinishBorderCosts
{
    public function __invoke(BorderCrossing $crossing, ?int $actorId = null): BorderCrossing
    {
        if (! Gate::allows('crossborder.manage')) {
            throw new InvalidArgumentException('Finishing a crossing\'s costs is cross-border management (§9.3).');
        }

        if ($crossing->status !== BorderCrossingStatus::Exited) {
            throw new InvalidArgumentException(sprintf(
                'The crossing at %s is %s. Costs are finished after the truck has exited.',
                $crossing->label(),
                $crossing->status->label(),
            ));
        }

        $captured = $crossing->costs()->count();

        if ($captured === 0) {
            throw new InvalidArgumentException(sprintf(
                'No charge has been captured at %s. If the gate was free, record the nil explicitly on the trip; a crossing cannot close on a guess.',
                $crossing->label(),
            ));
        }

        return DB::transaction(function () use ($crossing, $actorId, $captured): BorderCrossing {
            $crossing->moveTo(
                BorderCrossingStatus::CostsCaptured,
                null,
                ['charges' => $captured],
                ActorKind::User,
                $actorId,
            );

            return $crossing->refresh();
        });
    }
}
