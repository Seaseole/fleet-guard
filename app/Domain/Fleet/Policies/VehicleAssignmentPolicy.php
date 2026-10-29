<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Policies;

use App\Domain\Fleet\Models\VehicleAssignment;
use App\Models\User;

/**
 * Who may assign and release a vehicle.
 *
 * Assigning is a fleet-management act today (`fleet.update`); Phase 2's dispatch gate adds the trip
 * checks on top, it does not replace this permission. History rows are never deleted, so there is
 * no delete path to authorise.
 */
final class VehicleAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fleet.view');
    }

    public function view(User $user, VehicleAssignment $assignment): bool
    {
        return $assignment->company_id === $user->company_id
            && $user->can('fleet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fleet.update');
    }

    public function update(User $user, VehicleAssignment $assignment): bool
    {
        return $assignment->company_id === $user->company_id
            && $user->can('fleet.update');
    }

    public function delete(User $user, VehicleAssignment $assignment): bool
    {
        return false;
    }
}
