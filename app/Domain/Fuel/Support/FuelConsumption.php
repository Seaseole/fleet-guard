<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Support;

/**
 * The consumption arithmetic, in one place so the Action that judges and the screen that
 * prints cannot disagree about a percentage.
 *
 * Every figure is in the spec's two-decimal integer scale: litres per 100 km ×100, and a
 * signed percentage ×100. The division rounds half-up on both sides of zero, because PHP's `intdiv`
 * truncates toward zero and a variance of -6.005% must print as -6.01% just as +6.005% prints +6.01%.
 */
final class FuelConsumption
{
    /**
     * @return array{actual_x100: int, expected_x100: int, variance_pct_x100: int}
     */
    public static function compare(int $litresX100, int $distanceKm, float $expectedPer100): array
    {
        $expectedX100 = (int) round($expectedPer100 * 100);
        $actualX100 = intdiv($litresX100 * 100 + intdiv($distanceKm, 2), $distanceKm);
        $variancePctX100 = self::divideHalfUp(($actualX100 - $expectedX100) * 10000, $expectedX100);

        return [
            'actual_x100' => $actualX100,
            'expected_x100' => $expectedX100,
            'variance_pct_x100' => $variancePctX100,
        ];
    }

    /** Litres per 100 km, ×100, as the two-decimal string a column stores. */
    public static function litresLabel(int $x100): string
    {
        return number_format($x100 / 100, 2, '.', '');
    }

    /** Signed percentage, ×100, as "+14.20" — the caller appends the sign of its own sentence. */
    public static function varianceLabel(int $pctX100): string
    {
        return sprintf('%s%s', $pctX100 > 0 ? '+' : '', number_format($pctX100 / 100, 2, '.', ''));
    }

    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        return $numerator >= 0
            ? intdiv($numerator * 2 + $denominator, $denominator * 2)
            : -intdiv(-$numerator * 2 + $denominator, $denominator * 2);
    }
}
