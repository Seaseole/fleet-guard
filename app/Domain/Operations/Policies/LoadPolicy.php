<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Load;
use App\Models\User;

/**
 * Who may put freight on a trip and take it off (the "Load, booking and dispatch" row).
 *
 * The capability is `trips.plan`, the same one that names a truck: booking the load and booking the
 * vehicle are the same act of planning, and splitting them into two permissions would let a seat fill
 * a trailer it cannot dispatch.
 *
 * A load that has already travelled is not editable. The answer to a wrong record is a correcting
 * entry, not a rewrite, and the tonnage of a delivered load is the number a customer was billed
 * against — so after departure the row is frozen and the fix is a credit note's business (Phase 4).
 */
final class LoadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('trips.view');
    }

    public function view(User $user, Load $load): bool
    {
        return $load->company_id === $user->company_id
            && $user->can('trips.view');
    }

    public function create(User $user): bool
    {
        return $user->can('trips.plan');
    }

    public function update(User $user, Load $load): bool
    {
        return $load->company_id === $user->company_id
            && $user->can('trips.plan')
            && $load->trip?->status?->isPreDeparture() === true;
    }

    public function delete(User $user, Load $load): bool
    {
        return $this->update($user, $load);
    }
}
