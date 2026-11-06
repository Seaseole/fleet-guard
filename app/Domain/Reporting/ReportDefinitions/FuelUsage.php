<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Route;
use App\Domain\Operations\Models\Trip;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * Fuel consumption by truck and by the route its trip ran, over a window (the nominated
 * monthly report). Reversed issues are out and never come back: the reversal is the company's
 * own answer to a wrong slip, and a consumption figure that counted it twice would be
 * the number the variance process just refused.
 *
 * A slip whose trip carries no route prints its truck's row with an em-dash route — the litres
 * are real and belong to the truck even when the leg is unknown; the report never invents a
 * route the trip did not run (the simulator rule, read from the other side).
 */
final class FuelUsage implements ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $issues = FuelIssue::query()
            ->where('status', '!=', FuelIssueStatus::Reversed->value)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['truck_id', 'trip_id', 'litres_x100', 'cost_base']);

        if ($issues->isEmpty()) {
            return new ReportData(
                title: 'Fuel by truck and route',
                window: $from->format('d M Y').' → '.$to->format('d M Y'),
                columns: [
                    ['label' => 'Truck', 'numeric' => false],
                    ['label' => 'Route', 'numeric' => false],
                    ['label' => 'Litres', 'numeric' => true],
                    ['label' => 'Cost', 'numeric' => true],
                ],
                rows: [],
                note: 'No fuel was issued in this window.',
            );
        }

        $routeOfTrip = Trip::query()
            ->whereIn('id', $issues->pluck('trip_id')->filter()->unique()->all() ?: [0])
            ->pluck('route_id', 'id');

        $routes = Route::query()
            ->whereIn('id', $routeOfTrip->filter()->unique()->all() ?: [0])
            ->pluck('code', 'id');

        $trucks = Truck::query()->whereIn('id', $issues->pluck('truck_id')->unique()->all())->get()->keyBy('id');

        /** @var array<string, array{litres: int, cost: int}> $byTruckRoute */
        $byTruckRoute = [];

        foreach ($issues as $issue) {
            $routeId = $issue->trip_id !== null ? $routeOfTrip->get($issue->trip_id) : null;
            $key = $issue->truck_id.'|'.($routeId ?? 'none');

            $byTruckRoute[$key] ??= ['litres' => 0, 'cost' => 0];
            $byTruckRoute[$key]['litres'] += (int) $issue->litres_x100;
            $byTruckRoute[$key]['cost'] += (int) $issue->cost_base;
        }

        $rows = [];
        $litresTotal = 0;
        $costTotal = 0;

        $sorted = [];

        foreach ($byTruckRoute as $key => $figure) {
            [$truckId, $routeKey] = explode('|', $key);
            $sorted[$truckId.'-'.$routeKey] = [$truckId, $routeKey, $figure];
        }

        ksort($sorted);

        foreach ($sorted as [$truckId, $routeKey, $figure]) {
            $rows[] = [
                (string) ($trucks[(int) $truckId]->registration_no ?? 'truck '.$truckId),
                $routeKey === 'none' ? '—' : (string) ($routes[(int) $routeKey] ?? 'route '.$routeKey),
                Cells::litres($figure['litres']),
                Cells::minor($figure['cost'], $currency),
            ];

            $litresTotal += $figure['litres'];
            $costTotal += $figure['cost'];
        }

        return new ReportData(
            title: 'Fuel by truck and route',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: [
                ['label' => 'Truck', 'numeric' => false],
                ['label' => 'Route', 'numeric' => false],
                ['label' => 'Litres', 'numeric' => true],
                ['label' => 'Cost', 'numeric' => true],
            ],
            rows: $rows,
            totals: ['Total', '', Cells::litres($litresTotal), Cells::minor($costTotal, $currency)],
            note: 'Reversed slips are excluded — the reversal already answered them.',
        );
    }
}
