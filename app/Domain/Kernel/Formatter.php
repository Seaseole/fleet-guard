<?php

namespace App\Domain\Kernel;

/**
 * Screen formatting for the quantities that are not money (docs/DESIGN-SYSTEM.md §11).
 *
 * §11's contract is space thousands, a comma decimal and the unit attached — `1 842 km`,
 * `33,20 t`, `412,5 l`. Until this class existed, templates called `number_format()` inline,
 * which produces the English `1,842.00`, drops the unit half the time, and puts the same
 * convention in forty places instead of one. Money has its own door
 * ({@see Money\Money::format()}); this is everything else.
 *
 * Dates stay with Carbon's `format('d M Y')` until the date half of §14.8 lands here.
 */
final class Formatter
{
    /** Thousands separated by spaces, decimals by a comma — the §11 grouping rule. */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', ' ');
    }

    /** `1 842 km` — odometers and distances are recorded whole; pass 1 for a tenths figure. */
    public static function km(int|float $km, int $decimals = 0): string
    {
        return self::number($km, $decimals).' km';
    }

    /** `33,20 t` */
    public static function tonnes(int|float $tonnes, int $decimals = 2): string
    {
        return self::number($tonnes, $decimals).' t';
    }

    /** `412,5 l` */
    public static function litres(int|float $litres, int $decimals = 1): string
    {
        return self::number($litres, $decimals).' l';
    }

    /** `1 284,0 h` */
    public static function hours(int|float $hours, int $decimals = 1): string
    {
        return self::number($hours, $decimals).' h';
    }
}
