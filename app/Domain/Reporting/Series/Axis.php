<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Kernel\Money\Currency;

/**
 * The axis definitions a chart prints, built from the column's own unit rather than a guessed one.
 *
 * A tick is the one place a chart must do arithmetic — a stored `*_base` integer is not a number anyone reads
 * off a screen, and `litres_x100` is not litres. So each axis carries the scale and the docs/DESIGN-SYSTEM.md grouping parameters
 * and the browser only ever divides and joins. That keeps the currency symbol, the space-for-thousands and the
 * comma-for-decimals in PHP, where Money and Formatter already own them, instead of a second table of them in JS.
 */
final class Axis
{
    /**
     * @param  string  $id  the scale's own name; a chart's datasets refer to it by this
     * @return array{id: string, position: string, scale: int, grouping: string, decimal: string, decimals: int, prefix: string, label: string, drawGrid: bool}
     */
    public static function money(string $id, string $currencyCode, string $position = 'left', string $label = '', bool $drawGrid = true): array
    {
        $currency = Currency::of($currencyCode);

        return [
            'id' => $id,
            'position' => $position,
            // 10^minorUnits, read from the currency rather than assumed: a two-decimal base and a zero-decimal
            // one would otherwise print the same ledger figure at a hundred times its size.
            'scale' => 10 ** $currency->minorUnits(),
            'grouping' => ' ',
            'decimal' => ',',
            // Whole units on the ruler, the exact figure in the tooltip and the table: `P 128 400,00` is five
            // characters of noise between gridlines that are nowhere near that precise.
            'decimals' => 0,
            'prefix' => ((string) ($currency->symbol ?: $currency->code)).' ',
            'label' => $label,
            'drawGrid' => $drawGrid,
        ];
    }

    /**
     * `litres_x100` in, litres out — the fuel log stores hundredths of a litre.
     *
     * @return array{id: string, position: string, scale: int, grouping: string, decimal: string, decimals: int, suffix: string, label: string, drawGrid: bool}
     */
    public static function litres(string $id, string $position = 'left', string $label = '', bool $drawGrid = true): array
    {
        return [
            'id' => $id,
            'position' => $position,
            'scale' => 100,
            'grouping' => ' ',
            'decimal' => ',',
            'decimals' => 0,
            'suffix' => ' l',
            'label' => $label,
            'drawGrid' => $drawGrid,
        ];
    }

    /**
     * A count of things — trucks, invoices, open alerts. No scale, no symbol.
     *
     * @return array{id: string, position: string, scale: int, grouping: string, decimal: string, decimals: int, label: string, drawGrid: bool}
     */
    public static function count(string $id, string $position = 'left', string $label = '', bool $drawGrid = true): array
    {
        return [
            'id' => $id,
            'position' => $position,
            'scale' => 1,
            'grouping' => ' ',
            'decimal' => ',',
            'decimals' => 0,
            'label' => $label,
            'drawGrid' => $drawGrid,
        ];
    }
}
