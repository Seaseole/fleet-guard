<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Policies;

use App\Domain\Fleet\Models\Trailer;
use App\Models\User;

/**
 * Who may see and change a trailer ("Fleet"). State moves and disposal parallel a truck's;
 * there is no odometer capability because trailers without hubodometers record nothing.
 */
final class TrailerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fleet.view');
    }

    public function view(User $user, Trailer $trailer): bool
    {
        return $trailer->company_id === $user->company_id
            && $user->can('fleet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fleet.create');
    }

    public function update(User $user, Trailer $trailer): bool
    {
        return $trailer->company_id === $user->company_id
            && $user->can('fleet.update');
    }

    public function sell(User $user, Trailer $trailer): bool
    {
        return $trailer->company_id === $user->company_id
            && $user->can('fleet.sell');
    }

    public function delete(User $user, Trailer $trailer): bool
    {
        return false;
    }
}
