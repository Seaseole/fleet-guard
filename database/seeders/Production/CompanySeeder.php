<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Kernel\Tenancy\Company;
use Illuminate\Database\Seeder;

/**
 * The one row that makes every other default meaningful. Idempotent, because `migrate --seed`
 * and a deploy hook both call it, and because re-running it must never fork a second company.
 *
 * Real values come from the owner (Phase 0.5 intake); these are the shape, not invented data.
 */
final class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Fleet Guard',
                'base_currency_code' => 'BWP',
                'timezone' => 'Africa/Gaborone',
                'country_code' => 'BW',
                'fiscal_year_starts_on' => '2000-01-01',
                'settings' => [
                    // §17 Q3/Q4: restart policy and VAT mechanics are unresolved, so the
                    // defaults are declared here where one edit changes them everywhere.
                    'numbering' => [
                        'invoice' => ['prefix' => 'INV-', 'pad' => 5, 'restart' => 'financial_year'],
                        'credit_note' => ['prefix' => 'CN-', 'pad' => 5, 'restart' => 'financial_year'],
                        'trip' => ['prefix' => 'TRIP-', 'pad' => 4, 'restart' => 'never'],
                        'work_order' => ['prefix' => 'WO-', 'pad' => 4, 'restart' => 'never'],
                    ],
                    'thresholds' => [
                        'work_order_approval_minor' => 500_000,
                        'fuel_variance_alert_pct' => 15.0,
                    ],
                ],
            ],
        );
    }
}
