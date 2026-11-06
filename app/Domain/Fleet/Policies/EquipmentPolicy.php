<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Policies;

use App\Domain\Fleet\Models\Equipment;
use App\Models\User;

/**
 * Who may see and change yard equipment ("Fleet"). Disposal uses `fleet.sell` for the same
 * reason a truck's does: the capability is "take an asset out of the fleet", whatever its wheels.
 */
final class EquipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fleet.view');
    }

    public function view(User $user, Equipment $equipment): bool
    {
        return $equipment->company_id === $user->company_id
            && $user->can('fleet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fleet.create');
    }

    public function update(User $user, Equipment $equipment): bool
    {
        return $equipment->company_id === $user->company_id
            && $user->can('fleet.update');
    }

    public function sell(User $user, Equipment $equipment): bool
    {
        return $equipment->company_id === $user->company_id
            && $user->can('fleet.sell');
    }

    public function delete(User $user, Equipment $equipment): bool
    {
        return false;
    }
}
