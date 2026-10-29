<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Creates the Owner account (§9.1 owner-first). The only production path that mints an account with
 * full rights, reachable from `fleet:setup-owner` and `OwnerSeeder` — never from a route.
 *
 * Three guards, because this is the function an attacker most wants:
 * - one Owner per company (§9.1); a second Owner is how the top seat gets handed out quietly;
 * - the password goes through `Password::defaults()`, because nothing else checks a string typed
 *   into a terminal;
 * - the assignment happens with the company pinned as the tenancy context, so the pivot row cannot
 *   land with `company_id = null`.
 *
 * That third guard is why the existing-Owner lookup below reads the pivot tables directly instead of
 * using `$user->roles`: the relation filters on the **ambient** team id, and a CLI command has no
 * authenticated user, so `whereHas('roles')` would find nothing and report "no Owner yet" for a
 * company that has one. §20.4's queue-tenant leak is the same trap with a different trigger.
 */
final class CreateOwner
{
    public function __invoke(string $email, string $name, string $password, int $companyId): User
    {
        $email = mb_strtolower(trim($email));

        $existing = $this->ownerEmail($companyId);

        // Re-running setup against the address that already holds the seat is a password update;
        // anything else that owns it is refused.
        if ($existing !== null && $existing !== $email) {
            throw new RuntimeException(
                "Company {$companyId} already has an Owner ({$existing}). Change roles on the users screen rather than re-running setup (§9.1)."
            );
        }

        $this->assertPasswordAcceptable($password);

        $previous = CurrentCompany::tryId();
        CurrentCompany::force($companyId);

        try {
            return DB::transaction(function () use ($email, $name, $password, $companyId): User {
                /** @var Role $role */
                $role = Role::query()
                    ->where('name', RoleKey::Owner->value)
                    ->where('company_id', $companyId)
                    ->firstOrFail();

                /** @var User $user */
                $user = User::query()->firstOrNew(['email' => $email]);
                $isNew = ! $user->exists;

                if (! $isNew && $user->company_id !== $companyId) {
                    // Never silently move a person between companies: T2 says only a named
                    // migration moves a row across tenants.
                    throw new RuntimeException("{$email} already belongs to company {$user->company_id}.");
                }

                $user->name = $name;
                $user->company_id = $companyId;

                if ($isNew) {
                    // Verified by definition: there is no invitation and no registration form, and
                    // the Owner is the person holding the box. Later accounts prove the address by
                    // having the link delivered to it (AcceptInvitation).
                    //
                    // `Carbon::now()` rather than `now()`: `Date::use(CarbonImmutable)` is active
                    // (§20.4), so the helper returns a CarbonImmutable and the model's declared
                    // property is the mutable `Illuminate\Support\Carbon`.
                    $user->email_verified_at = Carbon::now();
                }

                $user->forceFill(['password' => Hash::make($password)])->save();

                if (! $user->roles->contains('id', $role->id)) {
                    $user->assignRole($role);
                }

                return $user;
            });
        } finally {
            CurrentCompany::force($previous);
        }
    }

    private function ownerEmail(int $companyId): ?string
    {
        /** @var User|null $owner */
        $owner = User::query()
            ->where('company_id', $companyId)
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.company_id', $companyId)
                ->where('roles.name', RoleKey::Owner->value))
            ->first();

        return $owner?->email;
    }

    private function assertPasswordAcceptable(string $password): void
    {
        Validator::validate(
            ['password' => $password],
            ['password' => Password::defaults()],
            ['password' => 'The Owner password does not meet the strength rules (§9.1).'],
        );
    }
}
