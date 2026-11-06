<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Fuel\Models\FuelVariance;
use App\Domain\Reporting\Support\Cells;

/**
 * The latest consumption variances in the window, signed against the zero the trip was expected to sit at —
 * the divergent bar of docs/REDESIGN-CONCEPT.md §4.
 *
 * Every figure comes off the `FuelVariance` row the variance machine already judged: `variance_pct_10_2` is
 * the stored verdict (inputs kept beside it, because a printed number must be the number that was decided
 * on), and the row's state supplies the tone. This door re-reads; it never re-derives.
 *
 * The sign carries the direction (right burns more per 100 km than the truck's expected rate, left less) so
 * the colour is free to carry the *judgement* — open, acknowledged, disputed, resolved — and never has to
 * mean two things at once. Bars run from a printed centre rail, so the zero is drawn rather than implied.
 */
final class FuelVarianceSpread
{
    /** A handful of rows read as a spread; a full register is the table under it, not a chart. */
    private const LIMIT = 10;

    /** @var array<string, string> tone → the badge colour that says the same word in the register */
    private const TONES = [
        'danger' => '--color-danger',
        'warning' => '--color-warning',
        'success' => '--color-success',
        'neutral' => '--color-fg-ink-muted',
    ];

    public function __invoke(ChartWindow $window): Series
    {
        $variances = FuelVariance::query()
            ->with(['trip:id,trip_no', 'truck:id,registration_no'])
            ->whereBetween('detected_at', [$window->from->startOfDay(), $window->to->endOfDay()])
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $labels = [];
        $data = [];
        $formatted = [];
        $colours = [];
        $patterns = [];
        $rows = [];

        foreach ($variances as $variance) {
            $signedHundredths = (int) round((float) $variance->variance_pct_10_2 * 100);
            // The digit work stays behind the app's own percent door; only the sign is added here, and it is
            // typographic '−' so the bar's direction and the figure never disagree on style.
            $magnitude = Cells::percent(abs($signedHundredths));
            $tone = $variance->state->tone();

            $labels[] = trim($variance->trip?->trip_no.' · '.($variance->truck?->registration_no ?? ''));
            $data[] = $signedHundredths;
            $formatted[] = ($signedHundredths < 0 ? '−' : '+').$magnitude;
            $colours[] = self::TONES[$tone] ?? self::TONES['neutral'];
            $patterns[] = in_array($tone, ['danger', 'warning'], true) ? 'stripe' : 'solid';

            $rows[] = [
                (string) $variance->trip?->trip_no,
                (string) $variance->truck?->registration_no,
                ($signedHundredths < 0 ? '−' : '+').$magnitude,
                $variance->state->label(),
            ];
        }

        return new Series(
            id: 'fuel-variance-spread',
            type: 'variance',
            title: __('Consumption variance, newest first'),
            ariaLabel: __('Divergent bars for :count consumption variances between :from and :to. :detail.', [
                'count' => count($rows),
                'from' => $window->from->format('d M Y'),
                'to' => $window->to->format('d M Y'),
                'detail' => implode('; ', array_map(
                    static fn (array $row): string => $row[0].' '.$row[2].' — '.$row[3],
                    $rows,
                )),
            ]),
            windowLabel: $window->label(),
            windowKey: $window->key(),
            labels: $labels,
            datasets: [[
                'label' => __('Variance against the expected rate'),
                'data' => $data,
                'formatted' => $formatted,
                'colour' => $colours,
                'pattern' => $patterns,
            ]],
            axes: [],
            table: [
                'caption' => __('Consumption variances between :window, newest first', ['window' => $window->label()]),
                'columns' => [
                    ['label' => __('Trip'), 'numeric' => false],
                    ['label' => __('Truck'), 'numeric' => false],
                    ['label' => __('Variance'), 'numeric' => true],
                    ['label' => __('State'), 'numeric' => false],
                ],
                'rows' => $rows,
                'totals' => [],
            ],
            note: $rows === []
                ? __('No trip crossed the variance threshold in this window.')
                : __('Right of the rail burns more per 100 km than the truck was expected to; left burns less. The tone is the row’s decision state, striped when the desk still owes it a judgement.'),
        );
    }
}
