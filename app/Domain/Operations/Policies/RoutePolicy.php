<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Route;
use App\Models\User;

/**
 * Who may see and change a corridor (§9.3 "Jobs and trips" — `routes.view` / `routes.manage`).
 *
 * Dispatch reads routes to plan a trip; the Operations Manager (and the Owner) maintain them. The
 * agreed prices on a route are a separate field gate (`seeCustomerTerms`), so this policy never
 * has to ask whether the seat may see money.
 */
final class RoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('routes.view');
    }

    public function view(User $user, Route $route): bool
    {
        return $route->company_id === $user->company_id
            && $user->can('routes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('routes.manage');
    }

    public function update(User $user, Route $route): bool
    {
        return $route->company_id === $user->company_id
            && $user->can('routes.manage');
    }

    /**
     * A corridor is retired with `active = false`, never deleted: rate cards and (from Phase 2)
     * trips point at it, and a deleted route would orphan the price history that explains them.
     */
    public function delete(User $user, Route $route): bool
    {
        return false;
    }
}
