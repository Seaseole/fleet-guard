<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * §17 Q4's default, as rows: 12% standard, a zero-rated line type and an exempt one.
 *
 * Q4 asks for the VAT rate, the registration number and the zero-rating treatment of export legs,
 * and its stated default is "configurable `tax_rates` (default 12%), zero-rated line type available".
 * That default is what this seeds — **the 12% is the design's placeholder, not a verified rate**: the
 * number the accountant must confirm before the first tax invoice is issued, which is why it lives in
 * a seed rather than in a CHECK or a constant. Changing it is a row edit and a new window, not a
 * deploy (§16 R5: a wrong answer here is a config change, not a migration).
 *
 * The three rows also exist to make the *kinds* real on day one. An owner who decides a Namibia leg
 * is zero-rated needs a rate to point a line at; if only `standard` were seeded, that decision would
 * become a migration.
 *
 * Idempotent, and company-keyed like the chart: `firstOrCreate` on (company, code, starts_on), the
 * table's own unique window key.
 */
final class TaxRateSeeder extends Seeder
{
    /** The design's stated default, in the table's scale: 12.00% is 120 000. */
    private const STANDARD_RATE_X10000 = 120_000;

    public function run(): void
    {
        Company::query()->orderBy('id')->each(function (Company $company): void {
            CurrentCompany::force($company->id);

            try {
                $count = $this->seedForCompany();
            } finally {
                CurrentCompany::forget();
            }

            $this->command?->info(sprintf('Company %d: %d tax_rates rows ensured', $company->id, $count));
        });
    }

    private function seedForCompany(): int
    {
        $rows = [
            TaxRate::make('VAT12', 'VAT standard rate', TaxRateKind::Standard, self::STANDARD_RATE_X10000, '2020-03-01', null, true),
            TaxRate::make('ZERO', 'Zero-rated (export and cross-border legs)', TaxRateKind::ZeroRated, 0, '2020-03-01'),
            TaxRate::make('EXEMPT', 'Exempt supplies', TaxRateKind::Exempt, 0, '2020-03-01'),
        ];

        $created = 0;

        foreach ($rows as $row) {
            $existing = TaxRate::query()
                ->where('company_id', CurrentCompany::id())
                ->where('code', $row->code)
                ->where('starts_on', $row->starts_on)
                ->exists();

            if ($existing) {
                continue;
            }

            $row->save();
            $created++;
        }

        return $created;
    }
}
