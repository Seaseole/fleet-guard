<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelIssueStatus;
use App\Domain\Fuel\FuelVarianceState;
use App\Domain\Fuel\Models\FuelIssue;
use App\Domain\Fuel\Models\FuelVariance;
use App\Domain\Fuel\Support\FuelConsumption;
use App\Domain\Kernel\Alerts\Actions\RaiseAlert;
use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Support\AlertDelivery;
use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * fuel-variance judgement, made when a trip completes: did this load burn more diesel than
 * the truck's own expected figure says it should have?
 *
 * The comparison is per 100 km, not per trip, because that is the only figure two trips of different
 * lengths can be held to. The **inputs are stored on the row** — distance, litres, tonnes and
 * the expected rate in force — so a decision made today still says what it was about when the
 * company's expectation changes next year.
 *
 * A variance under the configured threshold writes nothing: a number inside the noise is not a
 * finding, and a row per trip would turn the register into wallpaper. Above it, one row per trip —
 * the unique key is the guard, and a re-completed trip refreshes the figures only while the row is
 * still **open**; a decided row is a judgement about figures that have already been examined.
 *
 * The alert goes out through single door with the variance row as its subject, so resolving
 * the row can close the alert it raised.
 */
final class EvaluateFuelVariance
{
    public function __construct(
        private readonly RaiseAlert $raise,
        private readonly AlertDelivery $delivery,
        private readonly CompanySettings $settings,
    ) {}

    public function __invoke(Trip $trip): ?FuelVariance
    {
        $distanceKm = (int) round((float) $trip->distance_km_10_2);

        if ($distanceKm <= 0) {
            return null;
        }

        $litresX100 = (int) FuelIssue::query()
            ->where('trip_id', $trip->id)
            ->where('status', FuelIssueStatus::Posted->value)
            ->sum('litres_x100');

        if ($litresX100 <= 0) {
            return null;
        }

        $truck = Truck::query()->find($trip->truck_id);

        if ($truck === null || $truck->expected_consumption_per_100 === null) {
            return null;
        }

        if ((int) round((float) $truck->expected_consumption_per_100 * 100) <= 0) {
            return null;
        }

        $figures = FuelConsumption::compare($litresX100, $distanceKm, (float) $truck->expected_consumption_per_100);
        $variancePctX100 = $figures['variance_pct_x100'];

        $thresholdPctX100 = (int) round((float) $this->settings->get('fuel.variance_threshold_pct') * 100);

        if (abs($variancePctX100) < $thresholdPctX100) {
            return null;
        }

        $variance = DB::transaction(function () use ($trip, $truck, $distanceKm, $litresX100, $figures): FuelVariance {
            $existing = FuelVariance::query()->where('trip_id', $trip->id)->first();

            $columns = [
                'truck_id' => (int) $truck->id,
                'distance_km' => $distanceKm,
                'litres_x100' => $litresX100,
                'litres_per_100_10_2' => FuelConsumption::litresLabel($figures['actual_x100']),
                'expected_per_100_10_2' => FuelConsumption::litresLabel($figures['expected_x100']),
                'variance_pct_10_2' => number_format($figures['variance_pct_x100'] / 100, 2, '.', ''),
                'tonnes_carried_10_2' => number_format($trip->tonnes(), 2, '.', ''),
            ];

            if ($existing !== null) {
                // A decided row is a judgement on figures already examined; only an open row follows
                // the numbers when a reversal or a re-completion moves them.
                if (! $existing->isOpen()) {
                    return $existing;
                }

                $existing->forceFill($columns)->save();

                return $existing;
            }

            try {
                return FuelVariance::create($columns + [
                    'trip_id' => $trip->id,
                    'state' => FuelVarianceState::Open,
                    'detected_at' => Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The trip completed twice at once; the winner's row is the finding.
                return FuelVariance::query()->where('trip_id', $trip->id)->firstOrFail();
            }
        }, 3);

        if ($variance->isOpen()) {
            $this->raise($variance, $trip, $truck);
        }

        return $variance->refresh();
    }

    private function raise(FuelVariance $variance, Trip $trip, Truck $truck): void
    {
        $criticalX100 = (int) round((float) $this->settings->get('fuel.variance_critical_pct') * 100);
        $severity = abs((int) round((float) $variance->variance_pct_10_2 * 100)) >= $criticalX100
            ? AlertSeverity::Critical
            : AlertSeverity::Warning;

        $outcome = $this->raise->handle(new ReminderCandidate(
            type: AlertType::FuelVariance,
            severity: $severity,
            family: 'fuel_variance',
            daysBefore: null,
            subjectType: 'fuel_variance',
            subjectId: (string) $variance->id,
            occurredOn: Carbon::now(CurrentCompany::timezone()),
            bucket: $trip->id,
            title: sprintf('Fuel variance on %s: %s', $trip->trip_no, $variance->varianceLabel()),
            payload: [
                'trip_id' => $trip->id,
                'trip_no' => $trip->trip_no,
                'truck_id' => (int) $truck->id,
                'registration_no' => $truck->registration_no,
                'variance_pct' => (string) $variance->variance_pct_10_2,
                'litres_per_100' => (string) $variance->litres_per_100_10_2,
                'expected_per_100' => (string) $variance->expected_per_100_10_2,
                'distance_km' => $variance->distance_km,
                'band_days' => null,
            ],
        ));

        if ($outcome->shouldDeliver()) {
            /** @var list<string> $roles */
            $roles = (array) config('fleet.fuel.variance_recipients', ['owner', 'operations_manager']);

            $this->delivery->deliverToRoles($outcome->alert, $roles, 'the fuel variance on '.$trip->trip_no);
        }
    }
}
