<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover;

use App\Providers\DomainServiceProvider;

/**
 * The datasets the cutover engine can take (§15.2 Phase 0.5 and Phase 1's master data).
 *
 * One case is special: the opening trial balance posts a journal. Every other case applies to a
 * module's master tables through a handler bound in {@see DomainServiceProvider},
 * which is why this enum lives in the Kernel and knows nothing about the modules themselves.
 *
 * The dataset CHECK on `cutover_batches` is widened in the same migration that adds a case — the
 * same rule the alert types follow. A dataset the engine cannot validate must not be storable.
 *
 * `moneyColumns()` and `previewColumns()` are presentation facts about the file: which cells hold
 * minor units, and which columns the preview may print. The preview never prints a stored secret,
 * only its mask, and nothing ending in `_encrypted` reaches a screen or an export.
 */
enum CutoverDataset: string
{
    case OpeningTrialBalance = 'opening_trial_balance';
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Trucks = 'trucks';
    case Trailers = 'trailers';
    case Employees = 'employees';
    case Drivers = 'drivers';
    case Routes = 'routes';
    case Rates = 'rates';
    case Parts = 'parts';

    public function label(): string
    {
        return match ($this) {
            self::OpeningTrialBalance => 'Opening trial balance',
            self::Customers => 'Customers',
            self::Suppliers => 'Suppliers',
            self::Trucks => 'Trucks',
            self::Trailers => 'Trailers',
            self::Employees => 'Employees',
            self::Drivers => 'Drivers',
            self::Routes => 'Routes',
            self::Rates => 'Agreed rates',
            self::Parts => 'Parts',
        };
    }

    /** One line under the picker: what the file must carry and what it becomes. */
    public function summary(): string
    {
        return match ($this) {
            self::OpeningTrialBalance => 'Posts one journal: account_code, debit and credit per line. Nothing reaches the ledger until the Owner commits it.',
            self::Customers => 'Adds or updates customers by code (or name when the code is blank). Name is required; contact details, VAT, payment_terms and active are optional.',
            self::Suppliers => 'Adds or updates suppliers by code (or name). Name is required; category, contact details, VAT, payment_terms and active are optional.',
            self::Trucks => 'Adds or updates trucks by registration_no. fleet_no and vin must be unique when present; odometer_km only applies to a truck created by the import.',
            self::Trailers => 'Adds or updates trailers by registration_no. type is one of flatbed, tipper, curtainsider, tanker, lowboy.',
            self::Employees => 'Adds or updates employees by employee_no. national_id is encrypted at rest and only ever shown masked.',
            self::Drivers => 'Adds or updates the driver record for an existing employee_no. The licence number is encrypted at rest.',
            self::Routes => 'Adds or updates corridors by code. Country codes must be in the corridor set in config/fleet.php.',
            self::Rates => 'Files a price for a customer on a corridor. Rows that overlap a live card for the same route, customer and basis are refused.',
            self::Parts => 'Adds or updates catalogue parts by sku. unit_cost is in its own currency_code (the base currency when blank).',
        };
    }

    /**
     * The columns the file must carry. `active`, contacts and notes are optional everywhere they
     * exist: a legacy export usually has them, but a minimal hand-made file should not be refused.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::OpeningTrialBalance => ['account_code', 'debit', 'credit'],
            self::Customers, self::Suppliers => ['name'],
            self::Trucks, self::Trailers => ['registration_no'],
            self::Employees => ['employee_no', 'name', 'hired_on'],
            self::Drivers => ['employee_no'],
            self::Routes => ['code', 'origin', 'destination', 'origin_country_code', 'destination_country_code', 'distance_km'],
            self::Rates => ['route_code', 'customer_code', 'rate_type', 'rate_amount', 'currency_code', 'valid_from'],
            self::Parts => ['sku', 'name'],
        };
    }

    /** @return list<string> */
    public function optionalColumns(): array
    {
        return match ($this) {
            self::OpeningTrialBalance => ['account_name', 'description'],
            self::Customers => ['code', 'contact_name', 'contact_email', 'contact_phone', 'vat_number', 'payment_terms', 'active'],
            self::Suppliers => ['code', 'category', 'contact_name', 'contact_email', 'contact_phone', 'vat_number', 'payment_terms', 'active'],
            self::Trucks => [
                'fleet_no', 'vin', 'make', 'model', 'year', 'capacity_tonnes', 'odometer_km', 'engine_hours',
                'purchase_amount', 'purchased_on', 'tyre_size', 'service_interval_km', 'service_interval_hours',
                'service_interval_months', 'fuel_type', 'main_tank_litres', 'aux_tank_litres',
                'expected_consumption_per_100', 'active',
            ],
            self::Trailers => ['type', 'fleet_no', 'vin', 'capacity_tonnes', 'axle_config', 'odometer_km', 'active'],
            self::Employees => ['role_or_grade', 'left_on', 'gross_salary', 'pay_frequency', 'national_id', 'active'],
            self::Drivers => ['licence_no', 'licence_code', 'licence_expires_on', 'permit_expires_on', 'allowance_rate', 'fuel_efficiency_baseline', 'kiosk_enabled', 'status'],
            self::Routes => ['notes', 'active'],
            self::Rates => ['valid_to'],
            self::Parts => ['category', 'unit_cost', 'currency_code', 'reorder_level', 'truck_registration_no', 'active'],
        };
    }

    /** @return list<string> */
    public function columns(): array
    {
        return [...$this->requiredColumns(), ...$this->optionalColumns()];
    }

    /**
     * The columns whose value is integer minor units in the row's currency.
     *
     * A preview formats these with money rules; everything else prints as text. The column name is
     * the file's, so the person reading the error report sees the header they wrote.
     *
     * @return list<string>
     */
    public function moneyColumns(): array
    {
        return match ($this) {
            self::Trucks => ['purchase_amount'],
            self::Employees => ['gross_salary'],
            self::Drivers => ['allowance_rate'],
            self::Rates => ['rate_amount'],
            self::Parts => ['unit_cost'],
            default => [],
        };
    }

    /**
     * The columns a screen or an export may print, in the order the file carries them.
     *
     * The two secret inputs are replaced by their mask: the ciphertext in the row's payload is
     * named `*_encrypted` and is never a preview column.
     *
     * @return list<string>
     */
    public function previewColumns(): array
    {
        return match ($this) {
            self::Employees => $this->replaceColumn('national_id', 'national_id_masked'),
            self::Drivers => $this->replaceColumn('licence_no', 'licence_no_masked'),
            default => $this->columns(),
        };
    }

    public function isMasterData(): bool
    {
        return $this !== self::OpeningTrialBalance;
    }

    /** @return list<string> */
    private function replaceColumn(string $from, string $to): array
    {
        return array_map(
            static fn (string $column): string => $column === $from ? $to : $column,
            $this->columns(),
        );
    }
}
