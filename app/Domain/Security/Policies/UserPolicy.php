<?php

declare(strict_types=1);

namespace App\Domain\Security\Policies;

use App\Models\User;
use App\Providers\IdentityServiceProvider;

/**
 * §9.3, last row: "Users, roles, permissions ⚑ — **F only** (Owner)".
 *
 * The Owner's exclusivity is a policy rule, not a UI nicety: `users.manage` is in no other role's
 * permission list, and this class adds the refusals a bare permission check cannot express —
 * nobody changes their own role, and nothing crosses companies.
 *
 * Registered by {@see IdentityServiceProvider} rather than discovered: the package
 * auto-discovery looks for `App\Policies\UserPolicy`, and this project keeps its policies with
 * their modules (§13).
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $target->company_id === $user->company_id && $user->can('users.view');
    }

    public function invite(User $user): bool
    {
        return $user->can('users.manage');
    }

    /**
     * Changing a role is the action that can create an Owner, so it refuses self-service on top of
     * the permission: an Accountant with `users.manage` (there is none today, and §9.3 keeps it that
     * way) must not be able to promote themselves.
     */
    public function changeRole(User $user, User $target): bool
    {
        if ($target->company_id !== $user->company_id || ! $user->can('users.manage')) {
            return false;
        }

        return $target->id !== $user->id;
    }
}
