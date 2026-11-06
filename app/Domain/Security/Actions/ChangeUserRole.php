<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Identity\CompanyTeamResolver;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets a staff account's role — one role, because the design describes jobs rather than permission piles,
 * and two roles on one account is how a matrix stops being reviewable.
 *
 * `syncRoles()` is the whole write. The team id is never passed: it is read from the tenancy context
 * by {@see CompanyTeamResolver}, so "assigned into the right company" is
 * a property of the context rather than an argument someone can forget.
 */
final class ChangeUserRole
{
    public function __invoke(User $target, RoleKey $role): User
    {
        /** @var Role $row */
        $row = Role::query()
            ->where('name', $role->value)
            ->where('company_id', $target->company_id)
            ->firstOrFail();

        // The target's company has to be the in-force context for the pivot write, including when
        // this runs from a CLI command or a queued job where nobody is authenticated at all.
        $previous = CurrentCompany::tryId();

        DB::transaction(function () use ($target, $row): void {
            CurrentCompany::force($target->company_id);

            $target->syncRoles([$row]);
        });

        CurrentCompany::force($previous);

        // After the commit: forgetting the cache inside the transaction would let a concurrent read
        // repopulate it from the pre-commit rows.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $target;
    }
}
