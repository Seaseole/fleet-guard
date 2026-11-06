<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The edges of that carry no new facts — `assigned → loaded`, `departed → in_transit`,
 * `in_transit ⇄ at_border`, `arrived → pod_pending`, `pod_pending → delivered`.
 *
 * These are reported from the road: a phone at a border post says "we are at the gate", and the only
 * question is whether that move exists in the machine. It is deliberately **not** a general
 * "set the status" door — the five edges with real prerequisites (dispatch, departure, completion,
 * close, re-open) are separate Actions because each of them *tests something* and writes something
 * besides a state, and a single callable that could land any state would make those tests optional by
 * construction.
 *
 * The kiosk guard sits here as well: The design says a driver may capture facts but never decide
 * completion, and {@see TripStatus::requiresOfficeRole()} is the one place that line is drawn.
 */
final class AdvanceTrip
{
    /**
     * The edges this Action is allowed to walk. Anything else is a refusal, including the edges that
     * have their own Action — a caller that means to dispatch must say so by name.
     *
     * @var array<string, list<string>>
     */
    private const EDGES = [
        TripStatus::Departed->value => [TripStatus::InTransit->value],
        TripStatus::InTransit->value => [TripStatus::AtBorder->value, TripStatus::Arrived->value],
        TripStatus::AtBorder->value => [TripStatus::InTransit->value],
        TripStatus::Arrived->value => [TripStatus::PodPending->value],
        TripStatus::PodPending->value => [TripStatus::Delivered->value],
    ];

    public function __invoke(Trip $trip, TripStatus $to, ?int $actorId = null, ActorKind $actorKind = ActorKind::User): Trip
    {
        $from = $trip->status;

        $allowed = self::EDGES[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Trip %s cannot move from %s to %s through this door%s.',
                $trip->trip_no,
                $from->value,
                $to->value,
                $this->redirect($from, $to),
            ));
        }

        return DB::transaction(function () use ($trip, $to, $actorId, $actorKind): Trip {
            // The completion rule: a kiosk reports facts from the road, and only an office seat
            // decides that a delivery was made. The move itself is legal — this is the guard that
            // stops it from being made *by a token*, which is what the shared phone makes cheap.
            if ($actorKind === ActorKind::Kiosk && $to->requiresOfficeRole()) {
                throw new InvalidArgumentException(sprintf(
                    'Trip %s cannot be marked %s from a kiosk token: that is an office decision.',
                    $trip->trip_no,
                    $to->label(),
                ));
            }

            $trip->moveTo($to, null, [], $actorKind, $actorId);

            // The four timestamps are the columns a report reads without walking events, so the
            // arrival is stamped here rather than only recorded in the trail.
            if ($to === TripStatus::Arrived) {
                $trip->arrived_at = now();
            }

            $trip->save();

            return $trip->refresh();
        });
    }

    /**
     * A refusal that names the door the caller actually wanted.
     *
     * Every rejected edge here has a dedicated Action for a reason, and a dispatcher's wrapper that
     * guessed this class would otherwise read "not allowed" with nothing to act on.
     */
    private function redirect(TripStatus $from, TripStatus $to): string
    {
        $door = match (true) {
            $to === TripStatus::Planned => ' — use PlanTrip',
            $to === TripStatus::Loaded => ' — use MarkLoaded, which measures the tonnage against the truck and the trailer',
            $to === TripStatus::Assigned => ' — use DispatchTrip, which runs the validity checks',
            $to === TripStatus::Departed => ' — use DepartTrip, which records the opening odometer',
            $to === TripStatus::Completed => ' — use CompleteTrip, which runs the completion checklist',
            $to === TripStatus::Closed => ' — use CloseTrip, which freezes the costs',
            $to === TripStatus::Reopened => ' — use ReopenTrip (Owner, re-authenticated)',
            in_array($to, [TripStatus::Cancelled, TripStatus::Aborted], true) => ' — use CancelTrip or AbortTrip, both of which need a reason',
            default => '',
        };

        return $from === $to ? '' : $door;
    }
}
