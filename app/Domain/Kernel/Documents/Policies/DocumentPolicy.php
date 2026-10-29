<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Policies;

use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Identity\RoleKey;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * §9.3's documents row and §14.4's "role-based visibility via documents.visibility + policy".
 *
 * Two layers, and both are needed. The ability (`documents.view`, `.upload`, …) is the *kind* of
 * action a role may perform at all; `visibility` narrows *which papers* — a Dispatcher with
 * `documents.download` is not thereby able to read the vehicle finance contract. An ability alone
 * gives every role with it the whole register, and a visibility list alone cannot refuse an upload
 * by someone whose role has no upload ability, so neither layer substitutes for the other.
 *
 * `confidential` is the one rule that is not a narrowing of the list. A flag meaning "this is
 * sensitive" has to change the default, because an empty list otherwise reads as "visible to
 * everyone with the ability" — and a document marked confidential while left at that default would
 * be marked and not protected. Here it means the Owner plus whoever the list names.
 */
final class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        if (! $user->can('documents.view')) {
            return Response::deny('Your role does not hold documents.view.');
        }

        return $this->visibleTo($user, $document)
            ? Response::allow()
            : Response::deny('This document is restricted to the roles it names.');
    }

    public function upload(User $user, Document $document): bool
    {
        // You cannot add a version to a document you may not see: the upload would create a current
        // copy that its own register entry hides.
        return $user->can('documents.upload') && $this->visibleTo($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $user->can('documents.download') && $this->visibleTo($user, $document);
    }

    /**
     * §9.3 gives the delete column to the Owner only (⚑ on the row), and the route adds the
     * re-authentication. The policy does not re-check the re-auth window: that is the middleware's
     * job, and duplicating it here would mean two answers to "was this person just asked for their
     * password".
     */
    public function deleteVersion(User $user, Document $document): bool
    {
        return $user->can('documents.delete') && $this->visibleTo($user, $document);
    }

    public function void(User $user, Document $document): bool
    {
        return $user->can('documents.delete') && $this->visibleTo($user, $document);
    }

    /**
     * The visibility test, in both of its halves.
     *
     * An empty list is the permissive case — "nobody has narrowed this paper" — which is what makes
     * the column additive rather than a second permission system: every document filed before a
     * restriction exists stays visible to everyone the ability covers, and only the ones someone
     * deliberately named become restricted.
     */
    private function visibleTo(User $user, Document $document): bool
    {
        // §9.3 gives the Owner F on this row outright, so the Owner is the one role a narrowing
        // cannot lock out — the alternative is an owner who cannot read their own register because
        // somebody typed the wrong role into a visibility list.
        if ($user->hasRole(RoleKey::Owner->value)) {
            return true;
        }

        $roles = $document->visibilityRoles();

        if ($roles === []) {
            // Unnamed paper is visible to everyone the ability covers. `confidential` is the flag
            // that says otherwise without naming anyone: it is not a substitute for a list, it is
            // the statement that a general right to read the register is not a right to read this.
            return ! $document->confidential;
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
