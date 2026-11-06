<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\BulkTankMovementKind;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Ledger\Actions\ReversePosting;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\TripCostRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The correction: a posted fuel issue is **reversed, never edited**.
 *
 * The reversal is a mirror journal entry — the original's own lines with the sides swapped,
 * `reverses_entry_id` set and the issue as the netting group — posted at today, so the pair nets to
 * zero and both rows stay readable. When the diesel came from the yard tank, the litres and the
 * money go back onto the tank's book as a `reversal` movement, which keeps 1180 and the tank's
 * value agreeing to the cent. When it came from a slip, the litres go back onto the slip's remaining
 * figure by the register's own rule (a reversed issue is no longer dispensed).
 *
 * The reason is mandatory, on the row and in the operational trail: `reversed` is a destructive
 * state, and a correction nobody explained is indistinguishable from a disappearance.
 *
 * The trip costs may already be frozen. The design says a frozen set is corrected by re-opening the trip
 * *or posting a reversing entry* — this is that entry, and refusing it here would leave the only
 * wrong number the freeze was meant to protect permanently wrong.
 */
final class ReverseFuelIssue
{
    public function __construct(
        private readonly ReversePosting $reversePosting,
        private readonly TripCostRollup $rollup,
        private readonly EvaluateFuelVariance $evaluateVariance,
    ) {}

    public function __invoke(FuelIssue $issue, string $reason, ?int $actorId = null): FuelIssue
    {
        if (! Gate::allows('fuel.correct')) {
            throw new InvalidArgumentException('Correcting a fuel issue is an operations decision.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reversal needs the reason that will appear in the trail.');
        }

        if (mb_strlen($reason) > 160) {
            throw new InvalidArgumentException('Keep the reversal reason to 160 characters — it is the reason, not the file.');
        }

        if ($issue->status !== FuelIssueStatus::Posted) {
            throw new InvalidArgumentException(sprintf(
                'This issue is %s; only a posted issue can be reversed, and a reversal is final.',
                $issue->status->label(),
            ));
        }

        if ($issue->trip_cost_id === null) {
            throw new InvalidArgumentException('This issue names no trip cost, so there is no posted entry to mirror. Reconcile it by hand.');
        }

        $trip = $issue->trip_id === null
            ? null
            : Trip::query()->whereKey($issue->trip_id)->first();

        $reversed = DB::transaction(function () use ($issue, $reason, $actorId, $trip): FuelIssue {
            $today = Carbon::now(CurrentCompany::timezone());

            ($this->reversePosting)(
                originalSourceType: 'trip_cost',
                originalSourceId: (string) $issue->trip_cost_id,
                sourceType: 'fuel_issue_reversal',
                sourceId: $issue->id,
                nettingGroup: $issue->id,
                description: sprintf('Reversal of fuel issue · %s — %s', $issue->id, $reason),
                actorId: $actorId,
            );

            if ($issue->bulk_tank_id !== null) {
                $tank = BulkTank::query()->whereKey($issue->bulk_tank_id)->lockForUpdate()->first()
                    ?? throw new InvalidArgumentException('The bulk tank this issue drew from is gone, so the litres cannot be returned to stock.');

                $tank->recordMovement(
                    BulkTankMovementKind::Reversal,
                    $issue->litres_x100,
                    $issue->cost_base,
                    $today,
                    'fuel_issue_reversal',
                    $issue->id,
                    'Reversal: '.$reason,
                    $actorId,
                );
            }

            $issue->forceFill(['reversal_reason' => $reason])->save();
            $issue->moveTo(FuelIssueStatus::Reversed, $reason);

            if ($trip !== null) {
                $this->rollup->write($trip->refresh());
            }

            return $issue->refresh();
        }, 3);

        // A reversal changes what the trip burned. While the variance is still open it should follow
        // the corrected figure; once decided, it stays the judgement that was made.
        if ($trip !== null) {
            ($this->evaluateVariance)($trip->refresh());
        }

        return $reversed;
    }
}
