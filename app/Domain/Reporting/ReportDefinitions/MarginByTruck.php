<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * Margin per truck over a window (Phase 8; The decision 4: profitability reads the
 * operational tables, the ledger stays the statement of record — `reports:tie-to-ledger` is what
 * proves the two still tell the same story).
 *
 * The window is the trip's: a truck earned what its *arrived* trips charged in the window and
 * cost what its costs occurred in the window. Both figures exclude nothing a person can see —
 * ready and billed chargeables count (earned is earned, invoiced or not), which is exactly the
 * sentence `reports:tie-to-ledger` quotes when the ledger lags behind by unposted rows.
 */
final class MarginByTruck implements ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $trips = Trip::query()
            ->whereNotNull('truck_id')
            ->whereBetween('arrived_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['id', 'truck_id']);

        if ($trips->isEmpty()) {
            return new ReportData(
                title: 'Margin per truck',
                window: $from->format('d M Y').' → '.$to->format('d M Y'),
                columns: $this->columns(),
                rows: [],
                note: 'No trip arrived in this window, so no margin was earned in it.',
            );
        }

        $tripIds = $trips->pluck('id')->all();
        $truckOfTrip = $trips->pluck('truck_id', 'id');
        $arrivals = $trips->groupBy('truck_id')->map(static fn ($group): int => $group->count());

        $revenue = Chargeable::query()
            ->whereIn('trip_id', $tripIds)
            ->whereIn('status', [ChargeableStatus::Ready->value, ChargeableStatus::Billed->value])
            ->get(['trip_id', 'base_amount'])
            ->groupBy(fn (Chargeable $c): int => (int) $truckOfTrip->get($c->trip_id))
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        $cost = TripCost::query()
            ->whereIn('trip_id', $tripIds)
            ->get(['trip_id', 'base_amount'])
            ->groupBy(fn (TripCost $c): int => (int) $truckOfTrip->get($c->trip_id))
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        $truckIds = $revenue->keys()->merge($cost->keys())->unique()->values();
        $trucks = Truck::query()->whereIn('id', $truckIds)->get()->keyBy('id');

        $rows = [];
        $totals = [0, 0, 0];

        foreach ($truckIds->sortBy(fn (int $id): string => (string) ($trucks[$id]->registration_no ?? $id)) as $truckId) {
            $rev = (int) ($revenue[$truckId] ?? 0);
            $paid = (int) ($cost[$truckId] ?? 0);
            $margin = $rev - $paid;
            $basis = $rev > 0 ? (int) round($margin * 10_000 / $rev) : null;

            $rows[] = [
                (string) ($trucks[$truckId]->registration_no ?? 'truck '.$truckId),
                (string) ($arrivals[$truckId] ?? 0),
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
            title: 'Margin per truck',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: $this->columns(),
            rows: $rows,
            totals: ['Total', '', Cells::minor($totals[0], $currency), Cells::minor($totals[1], $currency), Cells::minor($totals[2], $currency), ''],
            note: 'Earned counts ready and billed chargeables on trips that arrived in the window; cost counts trip costs occurring in it. The ledger tie-out reads the same window.',
        );
    }

    /** @return list<array{label: string, numeric: bool}> */
    private function columns(): array
    {
        return [
            ['label' => 'Truck', 'numeric' => false],
            ['label' => 'Trips arrived', 'numeric' => true],
            ['label' => 'Earned', 'numeric' => true],
            ['label' => 'Cost', 'numeric' => true],
            ['label' => 'Margin', 'numeric' => true],
            ['label' => 'Margin %', 'numeric' => true],
        ];
    }
}
