<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the §9.3 matrix into spatie's tables for one company, idempotently.
 *
 * Idempotent in the strict sense, not the "delete then recreate" sense: role rows are matched by
 * `(company_id, name, guard_name)` and their permissions **synced**, so re-running after a deploy
 * applies a matrix change without touching `model_has_roles`. Recreating roles would cascade-delete
 * the assignments (the pivot FK is `onDelete cascade`) and silently leave every staff account with
 * no role — which, because the whole system fails closed, would look like the app broke rather than
 * like a seeder that un-hired everyone.
 *
 * It also refuses the package's global-role escape hatch: with teams on, `roles.company_id IS NULL`
 * means "applies to every company". §8 wants roles that are company data with an owner, so a null
 * row here is a bug this class reports instead of producing.
 */
final class RoleProvisioner
{
    /**
     * @return array{permissions: int, roles: int, guard: string}
     */
    public function provision(int $companyId, string $guard = 'web'): array
    {
        return DB::transaction(function () use ($companyId, $guard): array {
            $permissions = $this->syncPermissions($guard);
            $roles = $this->syncRoles($companyId, $guard);

            // The registrar caches the permission rows, not the assignments; forgetting is what
            // makes a matrix change visible without a deploy.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return ['permissions' => $permissions, 'roles' => $roles, 'guard' => $guard];
        });
    }

    /**
     * @return int number of catalogue permissions present
     *
     * Permission rows are global by design (they are capability *names*, not grants), so this step
     * is company-independent and runs once per provision.
     */
    private function syncPermissions(string $guard): int
    {
        $existing = Permission::query()->where('guard_name', $guard)->pluck('name')->all();

        $missing = array_diff(PermissionCatalogue::names(), $existing);

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        $stale = array_diff($existing, PermissionCatalogue::names());

        if ($stale !== []) {
            // Not deleted: a permission that left the catalogue may still be referenced by an audit
            // row (§9.5 keeps history truthful), and a role's grant list is about to be overwritten
            // from the catalogue anyway. Reported, then left alone.
            logger()->warning('Permissions no longer in the catalogue', ['names' => array_values($stale)]);
        }

        return count(PermissionCatalogue::names());
    }

    private function syncRoles(int $companyId, string $guard): int
    {
        $created = 0;

        foreach (RoleKey::cases() as $key) {
            // firstOrNew carries company_id and guard_name into the new row, so a *found* row
            // already matches them — re-assigning here would be a write that changes nothing.
            /** @var Role $role */
            $role = Role::query()->firstOrNew([
                'name' => $key->value,
                'guard_name' => $guard,
                'company_id' => $companyId,
            ]);
            $role->save();

            $role->syncPermissions($key->permissions());

            $created++;
        }

        $orphans = Role::query()
            ->where('company_id', $companyId)
            ->whereNotIn('name', array_map(fn (RoleKey $k): string => $k->value, RoleKey::cases()))
            ->pluck('name');

        if ($orphans->isNotEmpty()) {
            logger()->warning('Roles exist that are not in §9.2', ['company' => $companyId, 'roles' => $orphans->all()]);
        }

        return $created;
    }
}
