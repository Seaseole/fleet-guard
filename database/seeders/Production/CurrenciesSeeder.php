<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Kernel\Money\Currency;
use Illuminate\Database\Seeder;

/**
 * The currencies this business can actually be invoiced in. §5.4: BWP plus the SADC corridor
 * set — ZAR at Beitbridge, USD for fuel and cross-border contracts, ZMW and MZN on the Lusaka
 * and Beira runs, ZWG and BRL as the occasional ones.
 *
 * Reference data, not invented business data: a currency's minor-unit count is a fact about the
 * world, which is exactly why ADR-0004 #8 keeps it in a table instead of an enum.
 */
final class CurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        Currency::flushCache();

        foreach (self::list() as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency + ['is_active' => true],
            );
        }
    }

    /**
     * Corridor currencies in use plus their ISO 4217 codes and minor units.
     *
     * @return list<array{code: string, name: string, minor_units: int, rounding_mode: string, symbol: string}>
     */
    public static function list(): array
    {
        return [
            ['code' => 'BWP', 'name' => 'Botswana Pula', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'P'],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'R'],
            ['code' => 'USD', 'name' => 'US Dollar', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => '$'],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'K'],
            ['code' => 'MZN', 'name' => 'Mozambican Metical', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'MT'],
            ['code' => 'ZWG', 'name' => 'Zimbabwe Gold', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'Z$'],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'minor_units' => 2, 'rounding_mode' => 'half_up', 'symbol' => 'R$'],
        ];
    }
}
