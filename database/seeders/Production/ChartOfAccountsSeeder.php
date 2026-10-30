<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Kernel\Ledger\Data\AccountType;
use App\Domain\Kernel\Ledger\Data\Dimension;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * COA v1 — the accounts Phase 0's ledger needs, shaped for a Botswana haulage business (§15.2).
 *
 * It is a seed rather than a migration because the accountant edits it (§15 phase 1 "COA review
 * with the accountant"), and `is_system` is what protects the rows the application itself posts
 * against: a code an Action cites by name cannot be deleted without breaking a posting path.
 *
 * Codes are the stable identity, ids are not: Phase 0.5 posts opening balances against these
 * codes, and the accountant's own book maps through `external_ref` rather than a spreadsheet.
 *
 * Idempotent, and two-pass: parents before children, because `parent_id` is a self-referencing FK.
 */
final class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Per company, exactly as RolesSeeder does it: the accounts table is company-keyed (§8),
        // and CurrentCompany has to be forced because a seeder has no authenticated user.
        Company::query()->orderBy('id')->each(function (Company $company): void {
            CurrentCompany::force($company->id);

            // Counts are taken while the context is still forced: Account is a scoped model, and
            // reading it after forget() is exactly the fail-closed throw §8 T3 promises.
            try {
                $this->seedForCompany();

                $total = Account::query()->count();
                $postable = Account::query()->where('is_postable', true)->count();
            } finally {
                CurrentCompany::forget();
            }

            $this->command?->info(sprintf(
                'Company %d: %d gl_accounts rows (%d postable)',
                $company->id,
                $total,
                $postable,
            ));
        });
    }

    private function seedForCompany(): void
    {
        $rows = self::rows();

        // Two passes, because parent_id is a self-referencing FK: the section roots (1000, 2000, …)
        // exist before anything that cites them as a parent. Within pass 2 the array order is
        // parent-before-child by code (1100 before 1110), which the rows() list is written to keep.
        foreach ([false, true] as $needsParent) {
            foreach ($rows as $row) {
                $code = (string) $row['code'];
                $parentCode = $row['parent'] ?? null;

                /** @var list<Dimension> $requires */
                $requires = $row['requires'] ?? [];

                if (($parentCode !== null) !== $needsParent) {
                    continue;
                }

                $parent = $parentCode === null
                    ? null
                    : Account::query()->where('code', $parentCode)->value('id');

                if ($needsParent && $parent === null) {
                    throw new \RuntimeException("COA seed: account {$code} names parent {$parentCode}, which has not been seeded.");
                }

                Account::query()->firstOrCreate(
                    ['company_id' => CurrentCompany::id(), 'code' => $code],
                    [
                        'name' => (string) $row['name'],
                        'type' => $row['type'],
                        'normal_balance' => $row['normal'],
                        'parent_id' => $parent,
                        // Seeded rows are system rows: §6.1 marks them so Phase 1+ cannot delete an
                        // account an Action posts against by code.
                        'is_system' => true,
                        'is_postable' => (bool) $row['is_postable'],
                        'is_active' => true,
                        'allowed_dimension_mask' => self::mask($requires),
                    ],
                );
            }
        }
    }

    /** @param  array<int, Dimension>  $dimensions  a plain array: the seeder writes list literals */
    private static function mask(array $dimensions): int
    {
        return Dimension::maskOf($dimensions);
    }

    /**
     * Headers are the rollup rows a trial balance groups under; leaves are what a line may post
     * to. `requires` is the §6.1 mask — every line on that account must carry those dimensions.
     *
     * @return list<array<string, mixed>> one row per gl_accounts entry, read with the local types asserted below.
     *                                    A list, not a code-keyed map: PHP turns the numeric-string key '1000' into the integer 1000,
     *                                    so a code-keyed array would hand the loop int codes for what is a string column.
     */
    public static function rows(): array
    {
        return [
            // ── Assets ──
            ['code' => '1000', 'name' => 'Assets', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'is_postable' => false],
            ['code' => '1100', 'name' => 'Current assets', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1000', 'is_postable' => false],
            ['code' => '1110', 'name' => 'Petty cash', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            ['code' => '1120', 'name' => 'Bank — BWP operating', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            ['code' => '1130', 'name' => 'Bank — USD', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            ['code' => '1150', 'name' => 'Accounts receivable — customers', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true, 'requires' => [Dimension::Customer]],
            ['code' => '1160', 'name' => 'Driver advances receivable', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true, 'requires' => [Dimension::Driver]],
            ['code' => '1170', 'name' => 'Prepaid permits and customs deposits', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            // Phase 3 slice 3b: diesel in a bulk tank is an asset until a trip burns it (§6.8). No
            // dimensions — a tank is not a truck, a trip or a customer, and a dip book-keeping entry
            // has no leg to carry.
            ['code' => '1180', 'name' => 'Bulk fuel stock', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            // Phase 3 slice 3e: the stores shelf is an asset until a work order fits the part (§6.8).
            // No dimensions — the shelf is not a truck or a job, and the receipt that fills it has no
            // leg to carry; the issue's debit is where the truck and the work order are named.
            ['code' => '1190', 'name' => 'Parts stock', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1100', 'is_postable' => true],
            ['code' => '1200', 'name' => 'Fixed assets', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1000', 'is_postable' => false],
            ['code' => '1210', 'name' => 'Trucks and trailers', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1200', 'is_postable' => true],
            ['code' => '1220', 'name' => 'Workshop equipment', 'type' => AccountType::Asset, 'normal' => Side::Debit, 'parent' => '1200', 'is_postable' => true],
            ['code' => '1290', 'name' => 'Accumulated depreciation', 'type' => AccountType::Asset, 'normal' => Side::Credit, 'parent' => '1200', 'is_postable' => true],

            // ── Liabilities ──
            ['code' => '2000', 'name' => 'Liabilities', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'is_postable' => false],
            ['code' => '2100', 'name' => 'Current liabilities', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2000', 'is_postable' => false],
            ['code' => '2110', 'name' => 'Accounts payable — suppliers', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2100', 'is_postable' => true],
            ['code' => '2120', 'name' => 'Purchase tax / VAT payable', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2100', 'is_postable' => true],
            ['code' => '2130', 'name' => 'PAYE and employee deductions payable', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2100', 'is_postable' => true],
            ['code' => '2140', 'name' => 'Driver settlements payable', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2100', 'is_postable' => true, 'requires' => [Dimension::Driver]],
            ['code' => '2200', 'name' => 'Non-current liabilities', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2000', 'is_postable' => false],
            ['code' => '2210', 'name' => 'Vehicle finance loans', 'type' => AccountType::Liability, 'normal' => Side::Credit, 'parent' => '2200', 'is_postable' => true],

            // ── Equity ──
            ['code' => '3000', 'name' => 'Equity', 'type' => AccountType::Equity, 'normal' => Side::Credit, 'is_postable' => false],
            ['code' => '3010', 'name' => 'Owner capital', 'type' => AccountType::Equity, 'normal' => Side::Credit, 'parent' => '3000', 'is_postable' => true],
            ['code' => '3020', 'name' => 'Retained earnings', 'type' => AccountType::Equity, 'normal' => Side::Credit, 'parent' => '3000', 'is_postable' => true],

            // ── Revenue ──
            ['code' => '4000', 'name' => 'Revenue', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'is_postable' => false],
            ['code' => '4100', 'name' => 'Haulage revenue', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'parent' => '4000', 'is_postable' => true, 'requires' => [Dimension::Truck, Dimension::Trip, Dimension::Customer]],
            ['code' => '4200', 'name' => 'Waiting time and detention revenue', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'parent' => '4000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '4300', 'name' => 'Fuel and recharge revenue', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'parent' => '4000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '4400', 'name' => 'Cross-border clearing fees recharged', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'parent' => '4000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '4900', 'name' => 'Other income', 'type' => AccountType::Revenue, 'normal' => Side::Credit, 'parent' => '4000', 'is_postable' => true],

            // ── Expenses ──
            ['code' => '5000', 'name' => 'Expenses', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'is_postable' => false],
            ['code' => '5100', 'name' => 'Fuel', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Truck, Dimension::Trip]],
            // The tank, not the trip, disagrees with the book: a dip variance is measured in litres
            // the truck never burned, so 5100's truck+trip rule cannot carry it (§15.2 phase 3).
            ['code' => '5110', 'name' => 'Fuel stock variance', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
            ['code' => '5200', 'name' => 'Driver wages and allowances', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Driver]],
            ['code' => '5300', 'name' => 'Maintenance and repairs', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Truck]],
            ['code' => '5310', 'name' => 'Parts consumed', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Truck]],
            ['code' => '5320', 'name' => 'Tyres', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Truck]],
            // The shelf, not a truck, disagrees with the book: a count shortage is measured in units
            // no vehicle consumed, so 5310's truck rule cannot carry it (mirrors 5110 for diesel).
            ['code' => '5315', 'name' => 'Parts stock variance', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
            ['code' => '5400', 'name' => 'Border and customs costs', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            // §6.6's direct-cost buckets need three accounts the v1 chart did not carry: a toll is not
            // a customs charge, and putting a weighbridge fee or a police fine in "border" would make
            // the bucket report lie. All three require the trip so a direct cost cannot lose its leg.
            ['code' => '5410', 'name' => 'Tolls and road charges', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '5420', 'name' => 'Lodging and subsistence', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '5430', 'name' => 'Other trip direct costs', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Trip]],
            ['code' => '5500', 'name' => 'Vehicle finance and insurance', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
            ['code' => '5600', 'name' => 'Depreciation', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true, 'requires' => [Dimension::Truck]],
            ['code' => '5700', 'name' => 'Overheads (rent, utilities, admin)', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
            ['code' => '5800', 'name' => 'Bank charges and exchange differences', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
            ['code' => '5900', 'name' => 'Fines, penalties and overload charges', 'type' => AccountType::Expense, 'normal' => Side::Debit, 'parent' => '5000', 'is_postable' => true],
        ];
    }
}
