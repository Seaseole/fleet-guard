<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Fleet\Models\VehicleAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ends an assignment by writing `to_at` (§4.3, D1).
 *
 * Nothing is deleted and nothing is overwritten: the row remains the record of who had the truck
 * between two moments. Releasing an already-closed assignment is refused rather than silently
 * re-dated, because a second end time would make the first one a lie.
 */
final class ReleaseAssignment
{
    public function __invoke(VehicleAssignment $assignment, ?CarbonInterface $to = null): VehicleAssignment
    {
        return DB::transaction(function () use ($assignment, $to): VehicleAssignment {
            if (! $assignment->isOpen()) {
                throw AssignmentRejected::alreadyReleased();
            }

            $endsAt = $to === null ? CarbonImmutable::now() : CarbonImmutable::instance($to);

            if ($endsAt->lessThan($assignment->from_at)) {
                throw AssignmentRejected::releaseBeforeStart();
            }

            $assignment->forceFill(['to_at' => $endsAt])->save();

            return $assignment->refresh();
        });
    }
}
