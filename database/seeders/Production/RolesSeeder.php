<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Kernel\Identity\RoleProvisioner;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * Seeds the roles and the permission matrix for **every** company row, from the catalogue
 * rather than from a list in this file.
 *
 * Production data, not demo data: roles and permissions are how the app behaves, and a fresh
 * deploy that only ran `DatabaseSeeder` must still refuse a Dispatcher the trip margin.
 *
 * Per company, not global: roles are company-keyed, so a second company gets its own role rows
 * on the next provision instead of inheriting company 1's — and the loop is the cheapest proof that
 * the team key actually works, because a bug in the resolver shows up here as duplicate-key errors.
 */
final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = new RoleProvisioner;

        Company::query()->orderBy('id')->each(function (Company $company) use ($provisioner): void {
            CurrentCompany::force($company->id);

            $summary = $provisioner->provision($company->id);

            CurrentCompany::forget();

            $this->command?->info(sprintf(
                'Company %d: %d permissions, %d roles (guard %s)',
                $company->id,
                $summary['permissions'],
                $summary['roles'],
                $summary['guard'],
            ));
        });
    }
}
