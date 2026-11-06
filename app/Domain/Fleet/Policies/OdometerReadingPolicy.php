<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Policies;

use App\Domain\Fleet\Models\OdometerReading;
use App\Models\User;

/**
 * Who may record a reading (`fleet.odometer.record`).
 *
 * Readings are never edited or deleted: a wrong number is corrected by a new reading marked
 * `disputed` against it, which is the same append-only rule the design applies to state.
 */
final class OdometerReadingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fleet.view');
    }

    public function view(User $user, OdometerReading $reading): bool
    {
        return $reading->company_id === $user->company_id
            && $user->can('fleet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fleet.odometer.record');
    }

    public function update(User $user, OdometerReading $reading): bool
    {
        return false;
    }

    public function delete(User $user, OdometerReading $reading): bool
    {
        return false;
    }
}
