<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Exceptions\InvitationNotAcceptable;
use App\Domain\Security\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Turns an accepted invitation into a staff account (§9.1). This is the only code path that creates
 * a `users` row, because public registration is off — there is no controller, form or API route that
 * does it any other way.
 *
 * Three things the Action refuses to do:
 * - take a company id from the request (T2 — the tenant comes from the invitation row);
 * - mark the account verified by anything other than the link itself being used (the address was
 *   proven by delivering to it, which is why Fortify's verification step is skipped rather than the
 *   flag is set by hand somewhere else);
 * - leave a used invitation usable (the row is locked inside the transaction, and the accept write
 *   is part of the same transaction as the user insert, so a double-click cannot create two accounts).
 */
final class AcceptInvitation
{
    /**
     * @return User the newly created staff account
     *
     * @throws InvitationNotAcceptable
     */
    public function __invoke(string $token, string $name, string $password): User
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $name, $password): User {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()
                ->withoutCompanyScope()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw InvitationNotAcceptable::unknownToken();
            }

            if ($invitation->accepted_at !== null) {
                throw InvitationNotAcceptable::accepted();
            }

            if ($invitation->isExpired()) {
                throw InvitationNotAcceptable::expired($invitation->email);
            }

            // A guest has no company context, and the resolver derives one from the authenticated
            // user — so for the length of this transaction the invitation row is the tenant
            // authority. Pinning it here is what makes assignRole() land in the right company's
            // role set rather than in `model_has_roles.company_id = null`.
            CurrentCompany::force($invitation->company_id);

            try {
                if (User::query()->where('email', $invitation->email)->exists()) {
                    // The account appeared after the invitation was sent (a re-send race, or an
                    // Owner who created it twice). Refusing is the safe half: silently adopting the
                    // existing row would hand a role to somebody who was never invited.
                    throw InvitationNotAcceptable::accepted();
                }

                $user = new User([
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => $password,
                ]);

                $user->company_id = $invitation->company_id;
                $user->email_verified_at = Carbon::now();
                $user->save();

                $this->grantRole($user, $invitation);

                $invitation->forceFill(['accepted_at' => Carbon::now(), 'accepted_user_id' => $user->id])->save();

                return $user;
            } finally {
                CurrentCompany::forget();
            }
        });
    }

    private function grantRole(User $user, Invitation $invitation): void
    {
        /** @var Role|null $role */
        $role = Role::query()
            ->where('name', $invitation->role_key)
            ->where('company_id', $invitation->company_id)
            ->first();

        if ($role === null) {
            throw InvitationNotAcceptable::roleSetMissing($invitation->role_key, $invitation->company_id);
        }

        $user->assignRole($role);
    }
}
