<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * §10.2's `closed → reopened` — Owner only, ⚑ re-authenticated, reason mandatory.
 *
 * The reason is not bureaucracy: a re-opened trip changes numbers a period has already reported, so
 * six months later someone has to be able to answer "why does the March P&L differ from the March
 * report" in one query. The machine's own `transitionsRequiringReason()` list carries `reopened` for
 * exactly that, and the ability name `trips.reopen` is already in `PermissionCatalogue::REAUTH`, so
 * the re-auth gate is a property of the permission rather than of this class.
 *
 * Un-freezing costs is the substance of the move; the ledger consequence — that the correction must
 * post as a **delta** (§10.2: a reversal plus a new entry, never an edit) — belongs to the posting
 * slice, and this Action is where its callers start.
 */
final class ReopenTrip
{
    public function __invoke(Trip $trip, string $reason, ?int $actorId = null): Trip
    {
        if (! Gate::allows('reopen', $trip)) {
            throw new \InvalidArgumentException('Only the Owner may re-open a closed trip (§10.2).');
        }

        return DB::transaction(function () use ($trip, $reason, $actorId): Trip {
            // The flag is cleared *before* the move, because `moveTo()` issues the UPDATE and
            // `trips_frozen_is_closed` refuses a frozen row in any state but `closed`. Setting it
            // afterwards means two statements and a half-state the engine rightly rejects — the bug
            // this file's first run caught. One write, one truth.
            $trip->costs_frozen = false;

            $trip->moveTo(TripStatus::Reopened, $reason, [], ActorKind::User, $actorId);

            return $trip->refresh();
        });
    }
}
