<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Kernel\Money\Money;

/**
 * One money cell, formatted the same way on the screen, in the export, and in the scheduled
 * mail (minor units in, a currency-shaped string out, the dash for nothing).
 */
final class Cells
{
    public static function minor(?int $minorUnits, string $currencyCode, bool $dashZero = false): string
    {
        if ($minorUnits === null || ($dashZero && $minorUnits === 0)) {
            return '—';
        }

        return Money::ofMinor($minorUnits, $currencyCode)->format($currencyCode);
    }

    /** litres_x100 columns (fuel) arrive as hundredths; the cell says litres. */
    public static function litres(int $litresX100): string
    {
        return number_format($litresX100 / 100, 2);
    }

    public static function percent(?int $basisPoints): string
    {
        return $basisPoints === null ? '—' : number_format($basisPoints / 100, 1).'%';
    }
}
