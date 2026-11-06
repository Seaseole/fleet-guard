<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Models\User;

/**
 * Who may move a trip ("Trips", the "Who" column).
 *
 * Each method is one edge of the diagram, because the diagram is where the design says who is
 * allowed to make each claim. Two of them are load-bearing beyond the matrix:
 *
 * - `close` is the Accountant's and Owner's act — it freezes a period's costs, so it cannot ride on
 *   `trips.complete`, which a dispatcher holds;
 * - `reopen` is ⚑ re-auth in the middleware stack and Owner-only in, and the ability name is
 *   already in `PermissionCatalogue::REAUTH`, so granting it wider would silently widen the re-auth
 *   promise too.
 *
 * The kiosk principal is not a `User`, so it never reaches this policy: The token guard asks
 * {@see Trip::mayBeCompletedByKiosk()} and the scope on the token instead. That is also why nothing
 * here trusts a trip's own status — a driver must not be able to mark their own delivery approved
 * even if the state machine would let the move happen.
 */
final class TripPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('trips.view');
    }

    public function view(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.view');
    }

    public function create(User $user): bool
    {
        return $user->can('trips.plan');
    }

    /** Editing a trip's plan (dates, equipment) before it leaves; after that the facts are facts. */
    public function update(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.plan')
            && $trip->status->isPreDeparture();
    }

    public function plan(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.plan');
    }

    /** The `planned → assigned`, the transition that carries the licence and papers checks. */
    public function dispatch(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.dispatch');
    }

    /** `loaded`, `departed`, `arrived`, `pod_pending`: the operational moves a dispatcher makes. */
    public function advance(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.dispatch');
    }

    public function hold(User $user, Trip $trip): bool
    {
        return $this->advance($user, $trip) && $trip->status->isPreDeparture();
    }

    public function cancel(User $user, Trip $trip): bool
    {
        return $this->advance($user, $trip) && $trip->status->isPreDeparture();
    }

    /** Aborting a trip that has already left needs `trips.complete` — it is a decision about money. */
    public function abort(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.complete');
    }

    public function complete(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.complete');
    }

    public function close(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.close');
    }

    public function reopen(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.reopen');
    }

    /** POD approval belongs to whoever holds `pod.approve` — accounts or dispatch, never a driver. */
    public function approvePod(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('pod.approve');
    }

    /** A trip is never deleted once it has left; before departure a wrong plan is cancelled, not erased. */
    public function delete(User $user, Trip $trip): bool
    {
        return $trip->company_id === $user->company_id
            && $user->can('trips.plan')
            && $trip->status === TripStatus::Draft;
    }
}
