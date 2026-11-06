<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * The payload the cash trends card draws: three periods of cash buckets, and the layout of the
 * default one so the server-rendered first frame is the final frame — the same promise
 * {@see Series} makes with its table, kept for a chart that is SVG rather than canvas.
 *
 * The picture is a diverging column chart. Cash in rises from a printed zero rail, cash out falls
 * below it, both measured against one symmetric magnitude: a receipt and a payment are not parts of
 * one total, so they get directions rather than a stack, and a reader compares a month by its height
 * on either side of the same rail. This is `x-patterns.variance-bars` turned vertical.
 *
 * Amounts are minor units here and strings in the tables; the card formats in the door's own voice
 * (space grouping, comma decimals, the currency's symbol — DESIGN-SYSTEM.md 11), never in a locale
 * the page invents. The geometry constants are the drawing, not styling, and the browser re-lays the
 * buckets out to its own measured width with the same numbers.
 */
final readonly class CashTrendsPayload implements JsonSerializable
{
    public const PLOT_HEIGHT = 222;

    public const PLOT_WIDTH = 477; // the frame the server draws into; JS re-lays out to the measured box

    public const BAR_MAX_WIDTH = 34;

    public const BAR_MIN_WIDTH = 4;

    public const SLOT_GAP = 12; // the air between neighbouring buckets, never inside one

    public const END_INSET = 6; // head and foot room, so the tallest bar does not touch the frame

    public const MIN_BAR_HEIGHT = 2; // a bucket that moved is never an invisible hairline

    public const AXIS_STEPS = 3; // divisions on each side of the zero rail

    public const TIP_WIDTH = 208;

    public const TIP_HEIGHT = 96;

    public const LABEL_STRIDE = 44; // the width one x label needs before it stops colliding with its neighbour

    /**
     * @param  list<array{value: string, label: string, data: list<array{date: string, end: string, in: int, out: int}>}>  $periods
     * @param  array<string, array{caption: string, columns: list<array{label: string, numeric: bool}>, rows: list<list<string>>, totals: list<string>}>  $tables  one per period value
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $totalLabel,
        public string $inflowLabel,
        public string $outflowLabel,
        public string $ariaLabel,
        public string $windowLabel,
        public string $windowKey,
        public string $currency,
        public array $periods,
        public array $tables,
    ) {}

    /**
     * The default period laid out for the server render: both bars of every bucket, the round
     * symmetric top, the tick magnitudes and the month names. `barTicks` picks the smallest
     * 1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6 or 8 × 10ⁿ that holds the taller of the two sides, so a step
     * is always a number a reader can divide by eye, and one magnitude serves both directions.
     *
     * @return array{ticks: list<int|float>, top: float, zeroY: float, usable: float, barWidth: float, slot: float, columns: list<array{x: float, inHeight: float, outHeight: float, in: int, out: int}>, labels: list<array{x: float, text: string}>}
     */
    public function layout(): array
    {
        $data = $this->periods[0]['data'] ?? [];

        $max = 0;

        foreach ($data as $bucket) {
            $max = max($max, max(0, $bucket['in']), max(0, $bucket['out']));
        }

        $ticks = self::barTicks($max, self::AXIS_STEPS);
        $top = $ticks !== [] ? $ticks[count($ticks) - 1] : 1.0;
        $top = $top > 0 ? $top : 1.0;

        $zeroY = self::PLOT_HEIGHT / 2;
        $usable = $zeroY - self::END_INSET;

        $n = count($data);
        $slot = $n > 0 ? self::PLOT_WIDTH / $n : (float) self::PLOT_WIDTH;
        $barWidth = $n > 0 ? max(self::BAR_MIN_WIDTH, min(self::BAR_MAX_WIDTH, $slot - self::SLOT_GAP)) : (float) self::BAR_MAX_WIDTH;

        $columns = [];
        $labels = [];

        // A month name needs about 44 px to read; a window wider than the frame can hold drops the
        // labels in between rather than overprinting them. The browser repeats the arithmetic against
        // its own measured width.
        $every = $n > 0 ? (int) ceil($n / max(1, (int) floor(self::PLOT_WIDTH / self::LABEL_STRIDE))) : 1;

        foreach ($data as $j => $bucket) {
            $x = round($slot * $j + ($slot - $barWidth) / 2, 2);

            $columns[] = [
                'x' => $x,
                'inHeight' => self::barHeight($bucket['in'], $top, $usable),
                'outHeight' => self::barHeight($bucket['out'], $top, $usable),
                'in' => max(0, $bucket['in']),
                'out' => max(0, $bucket['out']),
            ];

            if ($j % $every === 0) {
                $labels[] = [
                    'x' => $x + $barWidth / 2,
                    'text' => CarbonImmutable::parse($bucket['date'])->format('M'),
                ];
            }
        }

        return [
            'ticks' => $ticks,
            'top' => $top,
            'zeroY' => $zeroY,
            'usable' => $usable,
            'barWidth' => $barWidth,
            'slot' => $slot,
            'columns' => $columns,
            'labels' => $labels,
        ];
    }

    /** The height one side of a bucket draws, in px: a real amount is never rounded away to nothing. */
    public static function barHeight(int $minor, float $top, float $usable): float
    {
        if ($minor <= 0) {
            return 0.0;
        }

        return round(min($usable, max(self::MIN_BAR_HEIGHT, $minor / $top * $usable)), 2);
    }

    /**
     * @return list<int|float>
     */
    public static function barTicks(float $max, int $steps = 5): array
    {
        if ($max <= 0) {
            return [0.0];
        }

        $exponent = floor(log10($max));
        $base = 10 ** $exponent;
        $top = $base * 10;

        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $multiplier) {
            if ($base * $multiplier >= $max) {
                $top = $base * $multiplier;
                break;
            }
        }

        $ticks = [];

        for ($i = 0; $i <= $steps; $i++) {
            $ticks[] = $top * $i / $steps;
        }

        return $ticks;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'totalLabel' => $this->totalLabel,
            'inflowLabel' => $this->inflowLabel,
            'outflowLabel' => $this->outflowLabel,
            'windowKey' => $this->windowKey,
            'currency' => $this->currency,
            'axis' => Axis::money('y', $this->currency),
            'periods' => $this->periods,
            'tables' => $this->tables,
            'geometry' => [
                'height' => self::PLOT_HEIGHT,
                'width' => self::PLOT_WIDTH,
                'barMax' => self::BAR_MAX_WIDTH,
                'barMin' => self::BAR_MIN_WIDTH,
                'slotGap' => self::SLOT_GAP,
                'inset' => self::END_INSET,
                'minBar' => self::MIN_BAR_HEIGHT,
                'tickSteps' => self::AXIS_STEPS,
                'labelStride' => self::LABEL_STRIDE,
                'tipWidth' => self::TIP_WIDTH,
                'tipHeight' => self::TIP_HEIGHT,
            ],
        ];
    }
}
