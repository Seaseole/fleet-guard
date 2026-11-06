<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Formatter;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Support\Cells;

/**
 * What each truck burned in the window, and what it cost — a comparison, not a trend.
 *
 * Grouped by truck alone. The `fuel_usage` report groups by truck **and** route (its key is `truck|route`), which
 * is the right shape for a corridor question and the wrong shape for the one a fleet card asks: "which truck is
 * drinking". Re-aggregating that report would have to drop the route split it exists to show, so this is its own
 * door over the same rows and the same exclusion.
 *
 * Reversed slips are out, and for the report's reason rather than a new one: the reversal is the
 * company's own answer to a wrong slip, and a consumption figure that counted it twice would be the number the
 * variance process just refused.
 *
 * Litres and cost share one chart on two axes because they are the two questions a fuel slip answers — but only
 * the left axis prints a grid, so the picture reads as one ruler with two units rather than two overlapping ones.
 */
final class FuelPerTruck
{
    public function __invoke(ChartWindow $window): Series
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $issues = FuelIssue::query()
            ->where('status', '!=', FuelIssueStatus::Reversed->value)
            ->whereBetween('created_at', [$window->from->startOfDay(), $window->to->endOfDay()])
            ->get(['truck_id', 'litres_x100', 'cost_base']);

        /** @var array<int, array{litres: int, cost: int}> $byTruck */
        $byTruck = [];

        foreach ($issues as $issue) {
            $truckId = (int) $issue->truck_id;

            $byTruck[$truckId] ??= ['litres' => 0, 'cost' => 0];
            $byTruck[$truckId]['litres'] += (int) $issue->litres_x100;
            $byTruck[$truckId]['cost'] += (int) $issue->cost_base;
        }

        // Most-burning first: the chart answers "who is the outlier", and a reader should not have to scan twenty
        // bars to find it. The table below keeps the same order, so the two read alike.
        uasort($byTruck, static fn (array $a, array $b): int => $b['litres'] <=> $a['litres']);

        $trucks = Truck::query()->whereIn('id', array_keys($byTruck) ?: [0])->get()->keyBy('id');

        $labels = [];
        $litres = [];
        $cost = [];
        $rows = [];
        $litresTotal = 0;
        $costTotal = 0;

        foreach ($byTruck as $truckId => $figure) {
            $truck = $trucks->get($truckId);

            // The axis carries the registration, which is the short unique word for a truck; the row carries the
            // name the register itself uses, because the table is the record and can afford the fleet number too.
            $labels[] = $truck === null ? 'truck '.$truckId : (string) $truck->registration_no;
            $litres[] = $figure['litres'];
            $cost[] = $figure['cost'];
            $litresTotal += $figure['litres'];
            $costTotal += $figure['cost'];

            $rows[] = [
                $truck === null ? 'truck '.$truckId : $truck->label(),
                Formatter::litres($figure['litres'] / 100),
                Cells::minor($figure['cost'], $currency),
            ];
        }

        return new Series(
            id: 'fuel-per-truck',
            type: 'bar',
            title: __('Fuel by truck'),
            ariaLabel: __('Bar chart of litres and cost per truck from :from to :to.', [
                'from' => $window->from->format('d M Y'),
                'to' => $window->to->format('d M Y'),
            ]),
            windowLabel: $window->label(),
            windowKey: $window->key(),
            labels: $labels,
            datasets: [
                [
                    'label' => __('Litres'),
                    'data' => $litres,
                    'formatted' => array_map(
                        static fn (int $x100): string => Formatter::litres($x100 / 100),
                        $litres,
                    ),
                    'colour' => '--color-accent',
                    'pattern' => 'solid',
                    'axis' => 'y',
                ],
                [
                    'label' => __('Cost'),
                    'data' => $cost,
                    'formatted' => array_map(static fn (int $minor): string => Cells::minor($minor, $currency), $cost),
                    // Two units on one picture: the second series is a line over the bars rather than a second
                    // set of them, and it differs by style as well as by axis
                    //.
                    'type' => 'line',
                    'colour' => '--color-fg-ink-secondary',
                    'pattern' => 'dash',
                    'axis' => 'y1',
                ],
            ],
            axes: [
                Axis::litres('y', label: __('Litres')),
                Axis::money('y1', $currency, position: 'right', label: __('Cost'), drawGrid: false),
            ],
            table: [
                'caption' => __('Fuel issued per truck between :window', ['window' => $window->label()]),
                'columns' => [
                    ['label' => __('Truck'), 'numeric' => false],
                    ['label' => __('Litres'), 'numeric' => true],
                    ['label' => __('Cost'), 'numeric' => true],
                ],
                'rows' => $rows,
                'totals' => [
                    __('Window'),
                    Formatter::litres($litresTotal / 100),
                    Cells::minor($costTotal, $currency),
                ],
            ],
            note: $rows === []
                ? __('No fuel was issued in this window.')
                : __(':trucks trucks burned :litres in the window, at :cost. Reversed slips are excluded.', [
                    'trucks' => count($rows),
                    'litres' => Formatter::litres($litresTotal / 100),
                    'cost' => Cells::minor($costTotal, $currency),
                ]),
        );
    }
}
