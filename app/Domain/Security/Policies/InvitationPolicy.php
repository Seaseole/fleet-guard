<?php

declare(strict_types=1);

namespace App\Domain\Security\Policies;

use App\Domain\Security\Actions\AcceptInvitation;
use App\Domain\Security\Models\Invitation;
use App\Models\User;

/**
 * Invitations are an Owner-only capability (§9.3 "Users, roles, permissions ⚑ — **F only**"), so
 * the policy is a single question with a company check in front of it.
 */
final class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    /**
     * Revoking is safe at any point in the invitation's life, so it is not blocked by expiry — a
     * stale link that still resolves to "expired" is fine, but a link for a role that should never
     * have been offered must stop working the moment someone with `users.manage` says so.
     */
    public function revoke(User $user, Invitation $invitation): bool
    {
        return $invitation->company_id === $user->company_id
            && $user->can('users.manage');
    }

    /**
     * Accepting is not a policy decision at all — the invitee is a guest with no user row yet. The
     * token itself is the credential, and {@see AcceptInvitation()} is
     * what proves it. Kept here so a `@can('accept')` in a Blade file finds a false rather than a
     * confusing "policy not defined" error.
     */
    public function accept(User $user, Invitation $invitation): bool
    {
        return false;
    }
}
