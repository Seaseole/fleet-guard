<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Production\ChartOfAccountsSeeder;
use Database\Seeders\Production\CompanySeeder;
use Database\Seeders\Production\CurrenciesSeeder;
use Database\Seeders\Production\RolesSeeder;
use Database\Seeders\Production\TaxRateSeeder;
use Illuminate\Database\Seeder;

/**
 * Production seeds only — the rows the application cannot start without.
 *
 * Demo data never runs from here. It lives in `Database\Seeders\Demo` and is invoked by class, so an
 * accidental `db:seed` on a real company cannot invent a truck, a customer or a fuel receipt. That
 * separation is the owner's rule, not a style preference.
 *
 * `OwnerSeeder` is deliberately absent: it needs credentials, and a seed command that silently
 * creates an administrative account with a default password is the kind of thing that reaches
 * production on a Friday. It runs from `fleet:setup-owner`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            CurrenciesSeeder::class,
            RolesSeeder::class,
            ChartOfAccountsSeeder::class,
            // Q4's default rate, so an invoice can point a line at a tax rate the day Phase 4's
            // first draft opens. A seed rather than a hard-coded 12% because the rate is a fact about
            // the country, editable by the accountant without a deploy.
            TaxRateSeeder::class,
        ]);
    }
}
