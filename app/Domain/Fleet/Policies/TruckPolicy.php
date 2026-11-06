<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Policies;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Identity\FieldGate;
use App\Models\User;

/**
 * Who may see and change a truck ("Fleet").
 *
 * Cost is not a row decision: purchase price renders behind
 * {@see FieldGate::SeeVehicleCost}, so this policy never asks about
 * money. Selling and recording an odometer are separate capabilities because they are separate jobs
 * — a Workshop Manager records mileage but does not dispose of an asset.
 */
final class TruckPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('fleet.view');
    }

    public function view(User $user, Truck $truck): bool
    {
        return $truck->company_id === $user->company_id
            && $user->can('fleet.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fleet.create');
    }

    public function update(User $user, Truck $truck): bool
    {
        return $truck->company_id === $user->company_id
            && $user->can('fleet.update');
    }

    /** The ⚑ row: recording a disposal is re-authenticated, and costs a `fleet.sell` grant. */
    public function sell(User $user, Truck $truck): bool
    {
        return $truck->company_id === $user->company_id
            && $user->can('fleet.sell');
    }

    public function recordOdometer(User $user, Truck $truck): bool
    {
        return $truck->company_id === $user->company_id
            && $user->can('fleet.odometer.record');
    }

    /** A truck is never deleted; `sold` is the terminal state. */
    public function delete(User $user, Truck $truck): bool
    {
        return false;
    }
}
