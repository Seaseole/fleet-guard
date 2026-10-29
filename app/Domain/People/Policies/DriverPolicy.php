<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\People\Models\Driver;
use App\Models\User;

/**
 * Who may see and change a driver (§9.3 "People", §10.1 status moves).
 *
 * State changes go through `ChangeDriverStatus`, which asks `people.manage` here and then runs the
 * §10.1 guard. A driver is never deleted — `left` is the terminal state, and the row is evidence of
 * who drove what.
 */
final class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('people.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $driver->company_id === $user->company_id
            && $user->can('people.view');
    }

    public function create(User $user): bool
    {
        return $user->can('people.manage');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $driver->company_id === $user->company_id
            && $user->can('people.manage');
    }

    public function changeStatus(User $user, Driver $driver): bool
    {
        return $driver->company_id === $user->company_id
            && $user->can('people.manage');
    }

    public function delete(User $user, Driver $driver): bool
    {
        return false;
    }
}
