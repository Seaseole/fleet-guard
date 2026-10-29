<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Models\Load;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takes a load off a trip that has not left yet (§4.1, §10.2).
 *
 * Deleting the row rather than zeroing its tonnage is the honest shape: a parcel that was cancelled at
 * the warehouse leaves no manifest line, and a 0 t row would both fail `loads_tonnes_positive` and
 * haunt the loading schedule as an undecided stop. The audit trail keeps the name of what was removed
 * and who removed it — a load that never travelled is a fact about the trip, not a secret.
 *
 * After departure this is refused outright. §10.1's answer to a wrong record is a correcting entry;
 * what crossed the weighbridge is what crossed it, and the money consequence belongs to a credit note
 * (Phase 4), not to an edit of the manifest.
 */
final class RemoveLoad
{
    public function __invoke(Load $load, ?int $actorId = null): void
    {
        $trip = $load->trip;

        if ($trip !== null && ! $trip->status->isPreDeparture()) {
            throw new InvalidArgumentException(sprintf(
                'The load "%s" is on trip %s, which has already departed. Its tonnage is a record now, not a plan (§10.1).',
                $load->commodity,
                $trip->trip_no,
            ));
        }

        DB::transaction(static fn () => $load->delete());
    }
}
