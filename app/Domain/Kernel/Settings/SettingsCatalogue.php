<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Settings;

use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The settings an Owner may change at runtime, and the shape each one has.
 *
 * This is a whitelist, not documentation: `UpdateCompanySettings` refuses a key that is not
 * here, and the screen renders from it, so adding a field is one entry that both the form and
 * the validator see. Everything else in `config/fleet.php` stays config — a deployment fact
 * (a scanner host, a crypto key) must not become a runtime edit, and the identity windows are
 * read while routes are registered, before any company context exists.
 *
 * **Two stores.** `store` says where a field's truth lives. Almost all of them are the company
 * settings blob, which `UpdateCompanySettings` owns. One does not: `tax.standard_rate_pct` is a
 * front door onto the `tax_rates` register, whose rows carry dated windows so a document keeps the
 * rate it was issued under (§17 Q4). Writing a second copy into the blob would put two answers to
 * "what was the VAT on this date?" in one application, so the field is read from and written to the
 * register through Invoicing's doors — and the Kernel cannot import a module (§3.1 D2), so the page
 * that renders both wires them, while this file only declares the shape.
 */
final class SettingsCatalogue
{
    /**
     * @return array<string, array{group: string, label: string, hint: string, type: 'int'|'float'|'money'|'choice', min?: int|float, max?: int|float, options?: list<string>, store?: 'blob'|'door'}>
     */
    public static function fields(): array
    {
        return [
            // §20.37 — the theme every device in this company opens in. A seat may still override it
            // for its own browser; that choice is client-side because it must apply before first paint.
            'appearance.theme' => [
                'group' => 'Appearance',
                'label' => 'Default theme',
                'hint' => 'What a fresh phone, yard terminal or browser shows on its first page. A person can still pick their own under Appearance, and this screen is where the company answer lives.',
                'type' => 'choice',
                'options' => ['light', 'dark', 'system'],
            ],
            // §20.37 — a front door onto the dated rate register, not a blob value: an invoice issued
            // under the old percentage must keep printing and posting the old one.
            'tax.standard_rate_pct' => [
                'group' => 'Tax',
                'label' => 'Standard VAT rate',
                'hint' => 'The percentage new documents charge from today. Changing it closes the current window and opens a new one — invoices, credit notes and bills already issued keep the rate frozen on their lines, and zero-rated and exempt kinds are untouched.',
                'type' => 'float',
                'min' => 0.01,
                'max' => 100,
                'store' => 'door',
            ],
            // The slip carries the price in an unsigned smallint of minor units, so 655.35 is the
            // ceiling a settings value can take without promising a figure the register cannot hold.
            'fuel.price_diesel' => [
                'group' => 'Fuel',
                'label' => 'Diesel — price per litre',
                'hint' => "Starts the price on an office slip. The slip's own figure is what gets recorded, so changing this never re-prices a litre already bought.",
                'type' => 'money',
                'min' => 0.01,
                'max' => 655.35,
            ],
            'fuel.price_petrol' => [
                'group' => 'Fuel',
                'label' => 'Petrol — price per litre',
                'hint' => "Starts the price on a driver's slip when the trip's truck burns petrol.",
                'type' => 'money',
                'min' => 0.01,
                'max' => 655.35,
            ],
            'fuel.price_paraffin' => [
                'group' => 'Fuel',
                'label' => 'Paraffin — price per litre',
                'hint' => "Starts the price on a driver's slip when the trip's truck burns paraffin.",
                'type' => 'money',
                'min' => 0.01,
                'max' => 655.35,
            ],
            'fuel.variance_threshold_pct' => [
                'group' => 'Fuel',
                'label' => 'Variance threshold',
                'hint' => "A trip is queried when its litres per 100 km exceed the truck's own figure by this percentage.",
                'type' => 'float',
                'min' => 0.1,
                'max' => 100,
            ],
            'fuel.variance_critical_pct' => [
                'group' => 'Fuel',
                'label' => 'Critical variance',
                'hint' => 'Above this, the variance is critical: the alert cannot be muted.',
                'type' => 'float',
                'min' => 1,
                'max' => 200,
            ],
            'maintenance.work_order_approval_threshold_base' => [
                'group' => 'Workshop',
                'label' => 'Work-order approval limit',
                'hint' => 'A work order estimated above this amount needs an approver before it can be worked.',
                'type' => 'money',
                'min' => 0,
                'max' => 10_000_000,
            ],
            'maintenance.due_soon_km' => [
                'group' => 'Workshop',
                'label' => 'Due soon — kilometres',
                'hint' => "How far before a kilometre-based service is due the register says 'due soon'.",
                'type' => 'int',
                'min' => 1,
                'max' => 100_000,
            ],
            'maintenance.due_soon_hours' => [
                'group' => 'Workshop',
                'label' => 'Due soon — hours',
                'hint' => "How far before an hour-based service is due the register says 'due soon'.",
                'type' => 'int',
                'min' => 1,
                'max' => 10_000,
            ],
            'maintenance.due_soon_days' => [
                'group' => 'Workshop',
                'label' => 'Due soon — days',
                'hint' => "How far before a date-based service is due the register says 'due soon'.",
                'type' => 'int',
                'min' => 1,
                'max' => 365,
            ],
            'documents.expiry_warning_days' => [
                'group' => 'Papers',
                'label' => 'Expiry warning',
                'hint' => 'Licences, permits and policies within this many days of expiry are shown as expiring soon.',
                'type' => 'int',
                'min' => 1,
                'max' => 365,
            ],
            'kiosk.ttl_hours_after_trip_end' => [
                'group' => 'Driver link',
                'label' => 'Token horizon',
                'hint' => 'How long after a trip ends the driver’s capture link keeps working.',
                'type' => 'int',
                'min' => 1,
                'max' => 720,
            ],
            'telematics.poll_seconds' => [
                'group' => 'Telematics',
                'label' => 'Map refresh',
                'hint' => 'How often the live map refreshes while it is open.',
                'type' => 'int',
                'min' => 10,
                'max' => 3600,
            ],
            'telematics.stale_after_minutes' => [
                'group' => 'Telematics',
                'label' => 'Stale after',
                'hint' => 'A position older than this is shown as stale rather than as where the truck is now.',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::fields());
    }

    /** Where a field's truth lives: the company blob unless the entry says otherwise. */
    public static function store(string $key): string
    {
        return self::fields()[$key]['store'] ?? 'blob';
    }

    /**
     * The keys `UpdateCompanySettings` may write. A door key in that array would store a second truth.
     *
     * @return list<string>
     */
    public static function blobKeys(): array
    {
        return array_values(array_filter(self::keys(), static fn (string $key): bool => self::store($key) === 'blob'));
    }

    /** @return list<string> */
    public static function doorKeys(): array
    {
        return array_values(array_filter(self::keys(), static fn (string $key): bool => self::store($key) === 'door'));
    }

    public static function isEditable(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }

    /**
     * The form property for a setting key: `fuel.variance_threshold_pct` →
     * `fuelVarianceThresholdPct`. Livewire reads a dot in a bound name as a nested path, which
     * is why the key is flattened before it ever reaches the component.
     */
    public static function property(string $key): string
    {
        return Str::camel(str_replace('.', '_', $key));
    }

    /** @return array<string, list<string>> group label => setting keys, in catalogue order */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::fields() as $key => $field) {
            $groups[$field['group']][] = $key;
        }

        return $groups;
    }

    /** @return array<string, list<string>> keyed by the Livewire property path */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::fields() as $key => $field) {
            $rules['settings.'.self::property($key)] = match ($field['type']) {
                'choice' => ['required', 'string', 'in:'.implode(',', self::options($key))],
                'int' => ['required', 'integer', 'min:'.self::min($key), 'max:'.self::max($key)],
                default => ['required', 'numeric', 'min:'.self::min($key), 'max:'.self::max($key)],
            };
        }

        return $rules;
    }

    /**
     * The three shape keys are read through these rather than indexed directly: a `choice` has no
     * band and a band has no options, and the catalogue is long enough that a field added without one
     * of them should answer with the honest empty value instead of a PHP notice inside a form rule.
     *
     * @return list<string>
     */
    public static function options(string $key): array
    {
        return self::fields()[$key]['options'] ?? [];
    }

    public static function min(string $key): int|float
    {
        return self::fields()[$key]['min'] ?? 0;
    }

    public static function max(string $key): int|float
    {
        return self::fields()[$key]['max'] ?? 0;
    }

    /**
     * Turn a validated form value into what the blob stores. Money is the one that is not a
     * cast: a major-unit amount becomes integer minor units at the screen boundary (§5.1), and
     * `Money::ofMajor()` refuses sub-minor precision rather than rounding it.
     */
    public static function normalise(string $key, mixed $value): mixed
    {
        $field = self::fields()[$key] ?? throw new InvalidArgumentException("Setting [{$key}] is not editable.");

        return match ($field['type']) {
            'choice' => self::choice($key, (string) $value),
            'int' => (int) $value,
            'float' => (float) $value,
            'money' => Money::ofMajor((string) $value, CurrentCompany::model()->base_currency_code)->minorUnits,
        };
    }

    /**
     * A choice is stored as one of its declared options or not at all. The form rule already asks
     * `in:`, and this is the same question from the side that writes the row: a value that arrived
     * from anywhere else (a queued job, a tinker session) must not land in the blob as a theme name
     * the renderer will later fail to read.
     */
    private static function choice(string $key, string $value): string
    {
        $options = self::fields()[$key]['options'] ?? [];

        if (! in_array($value, $options, true)) {
            throw new InvalidArgumentException(sprintf(
                'Setting [%s] takes one of %s, and "%s" is not among them.',
                $key,
                implode(', ', $options),
                $value,
            ));
        }

        return $value;
    }

    /** The major-unit string a money field is edited in. */
    public static function moneyForInput(string $key): string
    {
        $minor = (int) app(CompanySettings::class)->get($key);

        return (string) Money::ofMinor($minor, CurrentCompany::model()->base_currency_code)->toBigDecimal();
    }
}
