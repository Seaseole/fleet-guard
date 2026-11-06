<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\BorderCrossingDirection;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\Models\BorderPost;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Names a gate on a trip's route — the `planned`, the only state a crossing may be born in.
 *
 * The post's country is copied onto the row (see the model): the gate's own record may be corrected
 * later, but where *this truck* went must not change underneath a report. A retried plan returns the
 * crossing it already made rather than counting the same gate twice — `(trip, post, direction)` is
 * unique for exactly that reason, and the second insert loses the race to the index, not to a guess.
 *
 * A trip that never travelled cannot acquire a gate: `cancelled`, `aborted` and `closed` are refused
 * by name. `delivered` and `completed` are deliberately *not* on that list: a clerk backfilling a
 * gate from the driver's paper bag is the normal case, and refusing it would push the history into a
 * spreadsheet.
 */
final class PlanBorderCrossing
{
    public function __invoke(
        Trip $trip,
        BorderPost $post,
        BorderCrossingDirection $direction,
        ?int $actorId = null,
    ): BorderCrossing {
        if (! Gate::allows('crossborder.manage')) {
            throw new InvalidArgumentException('Planning a crossing is cross-border management, and this seat may not record it.');
        }

        if (in_array($trip->status, [TripStatus::Cancelled, TripStatus::Aborted, TripStatus::Closed], true)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s is %s and cannot acquire a border crossing. Re-open a closed trip first.',
                $trip->trip_no,
                $trip->status->label(),
            ));
        }

        if ($post->company_id !== CurrentCompany::id()) {
            throw new InvalidArgumentException('That border post belongs to another company.');
        }

        $existing = $this->find($trip, $post, $direction);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($trip, $post, $direction): BorderCrossing {
                $crossing = new BorderCrossing([
                    'trip_id' => $trip->id,
                    'border_post_id' => $post->id,
                    'country_code' => $post->country_code,
                    'direction' => $direction->value,
                    'status' => BorderCrossingStatus::Planned->value,
                ]);

                // The row starts at `planned`, so there is no transition to record — but the state
                // column and its clock are still written together, as every aggregate's are.
                $crossing->state_changed_at = Carbon::now();

                $crossing->save();

                return $crossing->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Two planners, one truck, one gate: the index wins and the loser reads the winner's row.
            $winner = $this->find($trip, $post, $direction);

            if ($winner !== null) {
                return $winner;
            }

            throw new InvalidArgumentException(sprintf(
                'The gate %s already has a %s crossing on trip %s.',
                $post->name,
                $direction->label(),
                $trip->trip_no,
            ));
        }
    }

    private function find(Trip $trip, BorderPost $post, BorderCrossingDirection $direction): ?BorderCrossing
    {
        return BorderCrossing::query()
            ->where('trip_id', $trip->id)
            ->where('border_post_id', $post->id)
            ->where('direction', $direction->value)
            ->first();
    }
}
