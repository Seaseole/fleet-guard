<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover;

use App\Domain\Kernel\Cutover\Actions\PostOpeningTrialBalance;
use App\Providers\DomainServiceProvider;

/**
 * The datasets the cutover engine can take (Phase 0.5 and Phase 1's master data).
 *
 * Two roads, and the difference is where the money lands. The opening trial balance is committed by
 * {@see PostOpeningTrialBalance}, which writes **one journal for
 * the whole file** and nothing else. Everything else — the nine master files and the two opening
 * party-detail files — is applied by a module handler bound in
 * {@see DomainServiceProvider}, because a row of those files becomes a record, and an opening
 * receivable's row becomes a record *and* its own balanced entry. This enum lives in the Kernel and
 * knows nothing about the modules themselves, which is why it can only describe the shape.
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
    case OpeningReceivables = 'opening_receivables';
    case OpeningPayables = 'opening_payables';
    case OpeningOdometerReadings = 'opening_odometer_readings';
    case OpeningTankStock = 'opening_tank_stock';
    case OpeningDriverAdvances = 'opening_driver_advances';
    case OpeningLoans = 'opening_loans';
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
            self::OpeningReceivables => 'Opening receivables',
            self::OpeningPayables => 'Opening payables',
            self::OpeningOdometerReadings => 'Opening odometer readings',
            self::OpeningTankStock => 'Opening tank stock',
            self::OpeningDriverAdvances => 'Unreconciled driver advances',
            self::OpeningLoans => 'Opening loan schedules',
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
            self::OpeningReceivables => 'Money customers already owed on cutover day. One row is one open item: customer_code, amount, due_on and the legacy document number. Each becomes a real issued invoice (no VAT) with Dr 1150 / Cr 3010 of its own, so ageing, statements and the payable tie-out find it — apply this before the trial balance, and leave 1150 out of that file.',
            self::OpeningPayables => 'Invoices the fleet had received and not yet paid on cutover day. One row is one open bill: supplier_code, amount, due_on and the supplier\'s document number. Each becomes a recorded bill with Cr 2110 against Dr 3010 — the cost belongs to the old system\'s P&L, not this one\'s. Enter a foreign bill at its base value: supplier bills stay in the company currency until the bill-side FX slice.',
            self::OpeningOdometerReadings => 'What each truck\'s face read on cutover day. One row is one reading: registration_no, read_on and odometer_km (engine_hours optional). Readings are stored even when they run backwards — the drop is the incident, and the truck\'s projection only ever rises.',
            self::OpeningTankStock => 'Diesel in the yard tanks on cutover day. One row is one tank: tank_name, litres and the value those litres are worth in the company currency. It moves the book and posts Dr 1180 / Cr 3010, so the first dip after cutover disagrees with a real opening figure — leave 1180 out of the trial balance file.',
            self::OpeningDriverAdvances => 'Cash a driver was given before cutover and has not settled. One row is one advance: employee_no, the legacy slip number, amount and the day it was paid. It posts Dr 1160 against Cr 3010 with the driver on the line — the money left the old system\'s bank, so the receivable is what comes forward.',
            self::OpeningLoans => 'A vehicle finance facility already drawn, with the principal still owing and the instalments still to come. It rebuilds the plan through the design\'s own schedule door and posts nothing: the liability is the trial balance\'s 2210 line, and this file is what says when it falls due. Apply it before the trial balance — not because either double-counts, but because a 2210 credit with no schedule behind it is a debt the 13-week forecast cannot.',
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
            // `reference` is required, not courtesy: it is the natural key that makes a retried batch
            // apply once. A legacy open item always arrived with somebody's document number, and a
            // file that cannot produce one is a control total — which is the trial balance's job, not
            // this one's (and the same rule every upsert importer matches by).
            self::OpeningReceivables => ['customer_code', 'reference', 'amount', 'due_on'],
            self::OpeningPayables => ['supplier_code', 'reference', 'amount', 'due_on'],
            self::OpeningOdometerReadings => ['registration_no', 'read_on', 'odometer_km'],
            self::OpeningTankStock => ['tank_name', 'litres', 'value'],
            self::OpeningDriverAdvances => ['employee_no', 'reference', 'amount', 'advanced_on'],
            self::OpeningLoans => ['reference', 'principal', 'annual_rate_percent', 'frequency', 'repayment_count', 'first_payment_on'],
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
            // A corridor customer's open item stays in the currency it will be paid in (the
            // settlement only accepts a receipt of the document's own currency), and `fx_rate` says
            // what one unit of it was worth on cutover day — the base figures the books carry.
            self::OpeningReceivables => ['description', 'currency_code', 'fx_rate', 'issued_on'],
            self::OpeningPayables => ['description', 'currency_code', 'fx_rate', 'bill_on'],
            self::OpeningOdometerReadings => ['engine_hours'],
            self::OpeningTankStock => ['read_on', 'description'],
            self::OpeningDriverAdvances => ['note'],
            self::OpeningLoans => ['truck_registration_no', 'supplier_code', 'bank_account_name', 'purpose', 'notes'],
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
            self::OpeningReceivables, self::OpeningPayables => ['amount'],
            self::OpeningDriverAdvances => ['amount'],
            self::OpeningLoans => ['principal'],
            self::OpeningTankStock => ['value'],            self::Employees => ['gross_salary'],
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

    /**
     * Whether the file is applied row-by-row by a module handler rather than committed as one
     * journal. The name is Phase 1's and has kept its meaning: a party-detail opening file is
     * "applied", not "committed", and it writes documents — not a control-total entry.
     */
    public function isMasterData(): bool
    {
        return $this !== self::OpeningTrialBalance;
    }

    /**
     * The datasets that open a balance of their own — a party's document, or a tank's litres — and
     * therefore own the account they post. The trial balance asks this list before it accepts a line
     * on such an account, because two doors posting the same control account is how an opening balance
     * sheet counts the same money twice.
     *
     * @return list<CutoverDataset>
     */
    public static function openingDetailDatasets(): array
    {
        return [self::OpeningReceivables, self::OpeningPayables, self::OpeningTankStock, self::OpeningDriverAdvances];
    }

    /**
     * The control account this opening file posts, or null for every file that writes no entry.
     * Held here rather than in the validators so the Kernel names the account once and the modules
     * never have to agree with each other about it. The loan schedule returns null on purpose: its
     * liability is the trial balance's `2210` line, and the plan only says when it falls due.
     */
    public function coversAccount(): ?string
    {
        return match ($this) {
            self::OpeningReceivables => '1150',
            self::OpeningPayables => '2110',
            self::OpeningTankStock => '1180',
            self::OpeningDriverAdvances => '1160',
            default => null,
        };
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
