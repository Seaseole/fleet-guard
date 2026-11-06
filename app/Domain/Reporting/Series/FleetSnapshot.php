<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Formatter;

/**
 * How the fleet is standing right now, as a proportion rather than as four cards.
 *
 * The four slices are the four states a truck can be in today; `sold` is excluded for the same reason the fleet
 * card excludes it (resources/views/pages/dashboard/⚡dashboard.blade.php's own `where('status','!=',sold)`) — a
 * terminated asset is not part of the fleet a dispatcher is reading. That is also what keeps the bar inside the
 * categorical limit: docs/DESIGN-SYSTEM.md the design allows four series, and a fifth state would have to be grouped
 * rather than added.
 *
 * This is a **snapshot**, and it says so: moving the window does not move it, because `trucks.status` is the
 * state as of this minute and no history table backs it. A chart that silently ignored the filter would be worse
 * than one that admits it.
 *
 * The type is `proportion` since the 2026-11 redesign (docs/REDESIGN-CONCEPT.md §4): the ring it replaces graded
 * C on accessibility — slices readable by hue alone — while a stacked bar carries each share as text beside its
 * own texture. The shell draws it server-side; no canvas, no Chart.js payload.
 */
final class FleetSnapshot
{
    /**
     * The four states plotted, in the order a reader works down them: usable, working, held, lost.
     *
     * @var list<FleetStatus>
     */
    private const STATES = [
        FleetStatus::Available,
        FleetStatus::OnTrip,
        FleetStatus::InWorkshop,
        FleetStatus::OutOfService,
    ];

    /**
     * The badge tone's own colour, so the ring and the register's badge say the same thing about the same state
     *. The two risk slices are striped as well as coloured — the design forbids hue carrying
     * the difference alone, and this app puts meaning on red.
     *
     * @var array<string, array{colour: string, pattern: string}>
     */
    private const RAMP = [
        'success' => ['colour' => '--color-success', 'pattern' => 'solid'],
        'accent' => ['colour' => '--color-accent', 'pattern' => 'solid'],
        'warning' => ['colour' => '--color-warning', 'pattern' => 'stripe'],
        'danger' => ['colour' => '--color-danger', 'pattern' => 'stripe'],
        'neutral' => ['colour' => '--color-fg-ink-muted', 'pattern' => 'solid'],
    ];

    public function __invoke(ChartWindow $window): Series
    {
        // One read of the register, counted in PHP: the same door the cards use, so a ring and a card cannot
        // answer with two different fleets.
        $counts = Truck::query()
            ->where('status', '!=', FleetStatus::Sold->value)
            ->select('status')
            ->get()
            ->countBy('status')
            ->all();

        $total = array_sum($counts);

        $data = [];
        $formatted = [];
        $colours = [];
        $patterns = [];
        $rows = [];

        foreach (self::STATES as $state) {
            $count = (int) ($counts[$state->value] ?? 0);
            $share = $total > 0 ? $count / $total * 100 : 0.0;
            $tone = self::RAMP[$state->tone()];

            $data[] = $count;
            $formatted[] = Formatter::number($share, 0).'%';
            $colours[] = $tone['colour'];
            $patterns[] = $tone['pattern'];

            $rows[] = [$state->label(), (string) $count, Formatter::number($share, 1).'%'];
        }

        return new Series(
            id: 'fleet-snapshot',
            type: 'proportion',
            title: __('Fleet by state'),
            ariaLabel: __('Stacked bar of :total trucks by state: :detail.', [
                'total' => $total,
                'detail' => implode(', ', array_map(
                    static fn (int $index, string $label): string => $label.' '.($data[$index] ?? 0),
                    array_keys(self::STATES),
                    array_map(static fn (FleetStatus $state): string => $state->label(), self::STATES),
                )),
            ]),
            windowLabel: __('Live at :date — the state is now, not the window\'s end', ['date' => $window->asAt()->format('d M Y')]),
            windowKey: $window->key(),
            labels: array_map(static fn (FleetStatus $state): string => $state->label(), self::STATES),
            datasets: [[
                'label' => __('Trucks'),
                'data' => $data,
                'formatted' => $formatted,
                'colour' => $colours,
                'pattern' => $patterns,
            ]],
            axes: [],
            table: [
                'caption' => __('Trucks by state as at :date', ['date' => $window->asAt()->format('d M Y')]),
                'columns' => [
                    ['label' => __('State'), 'numeric' => false],
                    ['label' => __('Trucks'), 'numeric' => true],
                    ['label' => __('Share'), 'numeric' => true],
                ],
                'rows' => $rows,
                'totals' => [__('Fleet'), (string) $total, $total > 0 ? '100%' : '0%'],
            ],
            note: __(':total trucks that are not sold. A sold asset is not part of the fleet a seat is reading.', [
                'total' => $total,
            ]),
        );
    }
}
