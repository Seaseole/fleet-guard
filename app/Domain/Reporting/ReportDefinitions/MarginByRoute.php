<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * Margin per route over a window (Phase 5's "profitability by truck/route/customer", read
 * the decision-4 way): the same population the per-truck sheet uses — ready+billed
 * chargeables on trips that *arrived* in the window against the trip costs occurring in it —
 * grouped to the lane the trip ran instead of the truck that ran it.
 *
 * This is the report that answers the corridor question the owner actually asks: does
 * Gaborone–Beira pay, or does it only look busy? The margin % is earned-basis, one decimal,
 * em-dash on no revenue — the same conventions `margin.pct` defines in the metric catalogue.
 */
final class MarginByRoute implements ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $trips = Trip::query()
            ->whereNotNull('route_id')
            ->whereBetween('arrived_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['id', 'route_id']);

        if ($trips->isEmpty()) {
            return new ReportData(
                title: 'Margin per route',
                window: $from->format('d M Y').' → '.$to->format('d M Y'),
                columns: $this->columns(),
                rows: [],
                note: 'No trip arrived in this window, so no lane earned in it.',
            );
        }

        $tripIds = $trips->pluck('id')->all();
        $routeOfTrip = $trips->pluck('route_id', 'id');
        $arrivals = $trips->groupBy('route_id')->map(static fn ($group): int => $group->count());

        $revenue = Chargeable::query()
            ->whereIn('trip_id', $tripIds)
            ->whereIn('status', [ChargeableStatus::Ready->value, ChargeableStatus::Billed->value])
            ->get(['trip_id', 'base_amount'])
            ->groupBy(fn (Chargeable $c): int => (int) $routeOfTrip->get($c->trip_id))
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        $cost = TripCost::query()
            ->whereIn('trip_id', $tripIds)
            ->get(['trip_id', 'base_amount'])
            ->groupBy(fn (TripCost $c): int => (int) $routeOfTrip->get($c->trip_id))
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        $routeIds = $revenue->keys()->merge($cost->keys())->unique()->values();
        $routes = Route::query()->whereIn('id', $routeIds)->get()->keyBy('id');

        $rows = [];
        $totals = [0, 0, 0];

        foreach ($routeIds->sortBy(fn (int $id): string => (string) ($routes[$id]->code ?? $id)) as $routeId) {
            $rev = (int) ($revenue[$routeId] ?? 0);
            $paid = (int) ($cost[$routeId] ?? 0);
            $margin = $rev - $paid;
            $basis = $rev > 0 ? (int) round($margin * 10_000 / $rev) : null;
            $route = $routes[$routeId] ?? null;

            $rows[] = [
                $route === null ? 'route '.$routeId : $route->code.' — '.$route->origin.' → '.$route->destination,
                (string) ($arrivals[$routeId] ?? 0),
                Cells::minor($rev, $currency),
                Cells::minor($paid, $currency),
                Cells::minor($margin, $currency),
                Cells::percent($basis),
            ];

            $totals[0] += $rev;
            $totals[1] += $paid;
            $totals[2] += $margin;
        }

        return new ReportData(
            title: 'Margin per route',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: $this->columns(),
            rows: $rows,
            totals: ['Total', '', Cells::minor($totals[0], $currency), Cells::minor($totals[1], $currency), Cells::minor($totals[2], $currency), ''],
            note: 'The same population as margin-per-truck — arrived trips, ready+billed chargeables, costs in-window — grouped by the lane (decision 4). A corridor at 0% earns nothing no matter how many trips it ran.',
        );
    }

    /** @return list<array{label: string, numeric: bool}> */
    private function columns(): array
    {
        return [
            ['label' => 'Route', 'numeric' => false],
            ['label' => 'Trips arrived', 'numeric' => true],
            ['label' => 'Earned', 'numeric' => true],
            ['label' => 'Cost', 'numeric' => true],
            ['label' => 'Margin', 'numeric' => true],
            ['label' => 'Margin %', 'numeric' => true],
        ];
    }
}
