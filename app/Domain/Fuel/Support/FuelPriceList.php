<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Support;

use App\Domain\Fleet\FuelType;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Tenancy\CurrentCompany;

/**
 * The company's pump price per fuel type, read as a starting figure rather than a rule.
 *
 * The price lives in settings so every clerk and every driver link begins from the same number;
 * the slip's own price is still what the register records, so changing the setting never re-prices
 * a litre already bought. Prices are base-currency minor units, exactly as every other money
 * setting is stored, and a slip in a foreign currency starts empty — the setting says nothing
 * about what that pump charged.
 */
final class FuelPriceList
{
    public function __construct(private readonly CompanySettings $settings) {}

    public function minor(FuelType $type): int
    {
        return (int) $this->settings->get('fuel.price_'.$type->value);
    }

    /** The major-unit string a price input starts with. */
    public function input(FuelType $type): string
    {
        return (string) Money::ofMinor($this->minor($type), CurrentCompany::model()->base_currency_code)->toBigDecimal();
    }
}
