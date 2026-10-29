<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\RouteRate;
use App\Models\User;

/**
 * Who may see and reprice a rate card (§9.3 "Jobs and trips").
 *
 * Reading a card is part of reading routes (`routes.view`); the *amount* on it is additionally
 * behind `seeCustomerTerms` at the field layer. Writing is `routes.manage` — §9.3 gives the
 * Operations Manager the routes row as well as the Owner, which is right: the person who negotiates
 * the corridor is the person who files the price.
 */
final class RouteRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('routes.view');
    }

    public function view(User $user, RouteRate $rate): bool
    {
        return $rate->company_id === $user->company_id
            && $user->can('routes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('routes.manage');
    }

    public function update(User $user, RouteRate $rate): bool
    {
        return $rate->company_id === $user->company_id
            && $user->can('routes.manage');
    }

    /**
     * A card is closed by setting `valid_to`, not deleted: a trip billed inside its window has to
     * keep pointing at the number that priced it.
     */
    public function delete(User $user, RouteRate $rate): bool
    {
        return false;
    }
}
