<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\TripCostRollup;

/**
 * Rewrites one trip's projection from the ledger, by id (§6.6, §13 D5).
 *
 * The rollup is Invoicing's business only in one respect: issuing an invoice is the first thing that puts
 * **revenue** on a trip, and §20.17 left `trips.revenue_base` honestly at zero until that happened. A
 * module that may not import `Trip` still needs the projection to move when its posting lands, so the
 * refresh is an Action with a string id rather than a service Invoicing reaches into.
 *
 * Idempotent by construction: the rollup recomputes from the ledger's own lines, so calling it twice writes
 * the same numbers. The caller runs it inside the invoice's transaction, so the projection and the entry
 * commit together — the same rule {@see RecordTripCost} follows.
 */
final class RefreshTripRollup
{
    public function __construct(
        private readonly TripCostRollup $rollup,
    ) {}

    public function __invoke(string $tripId): void
    {
        $trip = Trip::query()->find($tripId);

        // A trip that vanished mid-transaction has nothing to refresh; the invoice's own FK would have
        // refused the row before this could matter, so this is a no-op rather than an error.
        if ($trip !== null) {
            $this->rollup->write($trip);
        }
    }
}
