<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripCost;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * Margin per customer over a window (Phase 5's profitability trio, last member). Revenue
 * is the easiest there is to attribute — the chargeable names its customer — but *cost* needs a
 * decision the schema never made: a trip's costs belong to whom? To the job the trip ran, which
 * is the answer the ledger's trip dimension already implies: trip → job → customer. One trip
 * serving one job means one customer's freight carries that trip's diesel; a shared trip is a
 * costing question this report answers plainly rather than splitting silently.
 *
 * Same population and conventions as the truck and route sheets, so the three report's totals
 * agree by construction — the harness asserts exactly that.
 */
final class MarginByCustomer implements ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $trips = Trip::query()
            ->whereBetween('arrived_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['id', 'job_id']);

        $jobs = $trips->pluck('job_id')->filter()->unique();
        $customerOfJob = Job::query()->whereIn('id', $jobs)->pluck('customer_id', 'id');
        $customerOfTrip = $trips->mapWithKeys(
            static fn (Trip $t): array => [(string) $t->id => $t->job_id === null ? null : $customerOfJob[$t->job_id]],
        );

        $tripIds = $trips->pluck('id')->all();

        $revenue = Chargeable::query()
            ->whereIn('trip_id', $tripIds)
            ->whereIn('status', [ChargeableStatus::Ready->value, ChargeableStatus::Billed->value])
            ->get(['trip_id', 'customer_id', 'base_amount'])
            ->groupBy(static fn (Chargeable $c): int => (int) $c->customer_id)
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        // `0` collects the costs no customer owns — trips with no job, or a job that names no
        // customer. They print as their own line below rather than smearing the named rows.
        $cost = TripCost::query()
            ->whereIn('trip_id', $tripIds)
            ->get(['trip_id', 'base_amount'])
            ->groupBy(static function (TripCost $c) use ($customerOfTrip): int {
                $customer = $customerOfTrip[$c->trip_id] ?? null;

                return (int) $customer;
            })
            ->map(fn ($group): int => (int) $group->sum('base_amount'));

        $orphanCost = (int) ($cost[0] ?? 0);
        $orphanRevenue = (int) ($revenue[0] ?? 0);
        unset($cost[0], $revenue[0]);

        $customerIds = $revenue->keys()->merge($cost->keys())->unique()->filter()->values();
        $customers = Customer::query()->whereIn('id', $customerIds)->get()->keyBy('id');

        $rows = [];
        $totals = [0, 0, 0];

        foreach ($customerIds->sortBy(fn (int $id): string => (string) ($customers[$id]->name ?? $id)) as $customerId) {
            $rev = (int) ($revenue[$customerId] ?? 0);
            $paid = (int) ($cost[$customerId] ?? 0);
            $margin = $rev - $paid;
            $basis = $rev > 0 ? (int) round($margin * 10_000 / $rev) : null;

            $rows[] = [
                (string) ($customers[$customerId]->name ?? 'customer '.$customerId),
                Cells::minor($rev, $currency),
                Cells::minor($paid, $currency),
                Cells::minor($margin, $currency),
                Cells::percent($basis),
            ];

            $totals[0] += $rev;
            $totals[1] += $paid;
            $totals[2] += $margin;
        }

        if ($orphanCost > 0 || $orphanRevenue > 0) {
            $rows[] = [
                'Trips without a named customer (unattributable)',
                Cells::minor($orphanRevenue, $currency),
                Cells::minor($orphanCost, $currency),
                Cells::minor($orphanRevenue - $orphanCost, $currency),
                '—',
            ];
            $totals[0] += $orphanRevenue;
            $totals[1] += $orphanCost;
            $totals[2] += $orphanRevenue - $orphanCost;
        }

        return new ReportData(
            title: 'Margin per customer',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: [
                ['label' => 'Customer', 'numeric' => false],
                ['label' => 'Earned', 'numeric' => true],
                ['label' => 'Cost', 'numeric' => true],
                ['label' => 'Margin', 'numeric' => true],
                ['label' => 'Margin %', 'numeric' => true],
            ],
            rows: $rows,
            totals: ['Total', Cells::minor($totals[0], $currency), Cells::minor($totals[1], $currency), Cells::minor($totals[2], $currency), ''],
            note: 'Cost is attributed through the trip\'s job — one trip, one job, one customer carries that trip\'s costs. Costs on trips whose job names no customer appear as one unattributable line, never smeared across the named ones.',
        );
    }
}
