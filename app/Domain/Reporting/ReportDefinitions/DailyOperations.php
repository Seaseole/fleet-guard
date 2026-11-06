<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Models\Breakdown;
use App\Domain\Operations\ChargeableStatus;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Chargeable;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The dispatcher's morning page, in one table: yesterday's board, counted from the rows each
 * module owns — the same status vocabularies the dashboard cards use, so a figure cannot be one
 * thing on the board and another on the report (the one-definition rule).
 *
 * The window is one day: the counts of *work* are what the day's trips did (arrived, POD), while
 * the fleet states are the live ones at the moment of reading — a truck that came back is no
 * longer "on the road", and the definition says so rather than pretending the column is history.
 */
final class DailyOperations implements ReportBuilder
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $day = $from;
        $currency = CurrentCompany::model()->base_currency_code;

        $fuel = FuelIssue::query()
            ->where('status', '!=', FuelIssueStatus::Reversed->value)
            ->whereDate('created_at', $day->toDateString());

        $arrived = Trip::query()->whereDate('arrived_at', $day->toDateString())->count();
        $pod = Trip::query()->where('status', TripStatus::PodPending->value)->count();
        $revenueReady = Chargeable::query()
            ->whereIn('status', [ChargeableStatus::Ready->value, ChargeableStatus::Billed->value])
            ->whereHas('trip', fn ($q) => $q->whereDate('arrived_at', $day->toDateString()))
            ->sum('base_amount');

        $rows = [
            ['Jobs waiting a decision', (string) Job::query()->whereIn('status', [JobStatus::Enquiry->value, JobStatus::Quoted->value])->count()],
            ['Trips planned, not dispatched', (string) Trip::query()->where('status', TripStatus::Planned->value)->count()],
            ['Trips on the road now', (string) Trip::query()->whereIn('status', [
                TripStatus::Departed->value, TripStatus::InTransit->value, TripStatus::AtBorder->value, TripStatus::Arrived->value,
            ])->count()],
            ['Trips arrived on '.$day->format('d M'), (string) $arrived],
            ['Proof of delivery outstanding', (string) $pod],
            ['Trucks on the road (live)', (string) Truck::query()->where('status', FleetStatus::OnTrip->value)->count()],
            ['Trucks in the workshop (live)', (string) Truck::query()->where('status', FleetStatus::InWorkshop->value)->count()],
            ['Breakdowns raised on '.$day->format('d M'), (string) Breakdown::query()->whereDate('occurred_at', $day->toDateString())->count()],
            ['Fuel issued on '.$day->format('d M').' (litres)', Cells::litres((int) $fuel->sum('litres_x100'))],
            ['Fuel issued cost', Cells::minor((int) $fuel->sum('cost_base'), $currency)],
            ['Revenue earned by trips arrived that day', Cells::minor((int) $revenueReady, $currency)],
        ];

        return new ReportData(
            title: 'Daily operations summary',
            window: 'board read for '.$day->format('d M Y'),
            columns: [['label' => 'Measure', 'numeric' => false], ['label' => 'Figure', 'numeric' => true]],
            rows: $rows,
            note: 'Fleet states are live counts; trip figures are the day\'s own rows.',
        );
    }
}
