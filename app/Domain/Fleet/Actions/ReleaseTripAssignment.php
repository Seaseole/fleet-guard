<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\Models\VehicleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Releases the vehicle a trip was holding, by **trip id** (the `cancelled`, `aborted`, `on_hold`).
 *
 * Operations knows a trip, not an assignment row, and D5 says it may not import
 * `VehicleAssignment` to find one. The trip reference Phase 2 wrote onto the assignment is exactly the
 * key this needs, so the lookup and the release both stay here.
 *
 * Zero open assignments is not an error: a trip cancelled before it was ever dispatched reserved
 * nothing, and the hold-and-release cycle may already have been run by hand. The Action therefore
 * returns how many rows it closed, which is what a screen prints and what `verify-operations.php`
 * asserts.
 */
final class ReleaseTripAssignment
{
    public function __construct(private readonly ReleaseAssignment $release) {}

    public function __invoke(string $tripId): int
    {
        return DB::transaction(function () use ($tripId): int {
            // Which assets the trip is holding, read without a lock and only to learn the ids worth
            // taking one on.
            $promised = VehicleAssignment::query()
                ->where('trip_id', $tripId)
                ->whereNull('to_at')
                ->get(['id', 'truck_id', 'trailer_id']);

            // The asset rows come before the windows, in the order every other Action in this path takes
            // them — trip, truck, trailer, assignments. A release that locked only the windows would sit
            // opposite {@see AssignVehicle} with the two halves crossed: one holding a window and reaching
            // for a truck, the other holding a truck and reaching for a window, and MySQL choosing which
            // dispatcher loses.
            foreach ($promised->pluck('truck_id')->filter()->unique() as $truckId) {
                Truck::query()->whereKey((int) $truckId)->lockForUpdate()->first();
            }

            foreach ($promised->pluck('trailer_id')->filter()->unique() as $trailerId) {
                Trailer::query()->whereKey((int) $trailerId)->lockForUpdate()->first();
            }

            $open = VehicleAssignment::query()
                ->where('trip_id', $tripId)
                ->whereNull('to_at')
                ->lockForUpdate()
                ->get();

            $closed = 0;

            foreach ($open as $assignment) {
                try {
                    // A hold or a cancellation usually lifts a reservation that has not started yet,
                    // and `ReleaseAssignment::releaseBeforeStart()` rightly refuses an end time before
                    // the window opened. Ending it **at** its start is the honest answer — the truck
                    // was never actually held for that period — and it keeps this door from being the
                    // place where a refusal is quietly counted as a success.
                    $now = CarbonImmutable::now();
                    $endsAt = $assignment->from_at !== null && $now->lt(CarbonImmutable::instance($assignment->from_at))
                        ? CarbonImmutable::instance($assignment->from_at)
                        : null;

                    ($this->release)($assignment, $endsAt);
                    $closed++;
                } catch (AssignmentRejected $alreadyClosed) {
                    // Only a row that is genuinely no longer open may be skipped. Anything else must
                    // travel to the caller as a failure, or a held truck reads as free on the board.
                    if ($assignment->fresh()?->to_at === null) {
                        throw $alreadyClosed;
                    }

                    $closed++;
                }
            }

            return $closed;
        });
    }
}
