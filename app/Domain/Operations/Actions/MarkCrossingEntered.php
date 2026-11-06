<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\BorderCrossingStatus;
use App\Domain\Operations\Models\BorderCrossing;
use App\Domain\Operations\TripStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * `planned → entered`: the truck is at the gate, and where it is has become a fact.
 *
 * The transit document number is recorded here rather than at exit because it is the paper the
 * gate asks for on the way in, and a number typed while the queue moves is a number somebody
 * actually read off the page. It may still be filled in later — the column is optional and the
 * exit Action does not touch it.
 *
 * The trip's own machine is not moved. A crossing records what happened at the gate; whether the
 * trip reads `at_border` or `in_transit` is the dispatcher's call through `AdvanceTrip`, and an
 * Action that silently seized the trip's state would make the trail claim a move nobody made.
 */
final class MarkCrossingEntered
{
    public function __invoke(
        BorderCrossing $crossing,
        ?string $transitDocNo = null,
        ?int $actorId = null,
        ?CarbonInterface $at = null,
    ): BorderCrossing {
        if (! Gate::allows('crossborder.manage')) {
            throw new InvalidArgumentException('Recording a gate entry is cross-border management.');
        }

        if ($crossing->status !== BorderCrossingStatus::Planned) {
            throw new InvalidArgumentException(sprintf(
                'The crossing at %s is already %s. Only a planned crossing can be entered.',
                $crossing->label(),
                $crossing->status->label(),
            ));
        }

        $this->assertTripStillTravels($crossing);

        $doc = $transitDocNo === null ? null : trim($transitDocNo);

        if ($doc !== null && mb_strlen($doc) > 60) {
            throw new InvalidArgumentException('A transit document number is at most 60 characters — it is a reference, not the document.');
        }

        return DB::transaction(function () use ($crossing, $doc, $actorId, $at): BorderCrossing {
            $moment = $at === null ? Carbon::now() : Carbon::instance($at);

            $crossing->entered_at = $moment;

            if ($doc !== null && $doc !== '') {
                $crossing->transit_doc_no = $doc;
            }

            $crossing->moveTo(
                BorderCrossingStatus::Entered,
                null,
                ['entered_at' => $moment->toIso8601String()],
                ActorKind::User,
                $actorId,
            );

            return $crossing->refresh();
        });
    }

    /** A gate event on a trip that was cancelled, aborted or closed would be a record of a trip nobody took. */
    private function assertTripStillTravels(BorderCrossing $crossing): void
    {
        $trip = $crossing->trip;

        if ($trip === null || in_array($trip->status, [TripStatus::Cancelled, TripStatus::Aborted, TripStatus::Closed], true)) {
            throw new InvalidArgumentException(sprintf(
                'The crossing sits on a trip that is %s. A gate event has to belong to a trip that travelled.',
                $trip?->status->label() ?? 'missing',
            ));
        }
    }
}
