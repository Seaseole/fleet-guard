<?php

declare(strict_types=1);

namespace App\Domain\People\Alerts;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Documents\Validity;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

/**
 * §14.1's `doc_expiry` / `permit_expiry` reminder for a driver's own papers.
 *
 * Phase 1 stores the licence and PDP expiry **on the driver row** (§4.3), not on the scan of the
 * card, because §10.2's dispatch gate reads one column. This source is what makes that choice safe:
 * it raises the same two alert types the document register raises, addressed to the people who book
 * trucks, so moving the dates off the document did not move the reminder off the register.
 *
 * It uses the document families `licence` and `permit` rather than inventing `driver_licence`: the
 * Owner configures "how early do we hear about a licence" once, and a scanned licence (which carries
 * no expiry by design) cannot collide with the driver row's date because the cooldown key includes
 * the subject (`driver:<id>:<date>` versus `document:<id>:<date>`).
 *
 * Departed drivers are not scanned. A person who left in March should not keep producing
 * compliance reminders in June; `suspended` and `standby` drivers still do, because their papers are
 * what the next un-suspension depends on.
 */
final class DriverPaperExpirySource implements ReminderSource
{
    private const ImminentTighteningDays = 14;

    public function key(): string
    {
        return 'drivers';
    }

    /**
     * @return list<Threshold>
     */
    public function defaultThresholds(): array
    {
        /** @var list<int> $buckets */
        $buckets = (array) config('fleet.reminders.default_buckets', [60, 30, 14, 7]);

        $defaults = [];

        foreach (['licence', 'permit'] as $family) {
            foreach ($buckets as $days) {
                // §14.1's own example: a `doc_expiry` alert reaches the Operations Manager *and* the
                // Dispatcher, because the person who books the truck is the one who can reassign it.
                $defaults[] = new Threshold($family, $days, ['operations_manager', 'dispatcher']);
            }
        }

        return $defaults;
    }

    /**
     * @return iterable<ReminderCandidate>
     */
    public function candidates(ThresholdSet $thresholds, CarbonInterface $businessToday): iterable
    {
        if ($thresholds->count() === 0) {
            return [];
        }

        $widest = 0;

        foreach ($thresholds->all() as $threshold) {
            $widest = max($widest, $threshold->daysBefore);
        }

        $horizon = CarbonImmutable::instance($businessToday)->startOfDay()->addDays($widest);

        foreach ($this->driversWithPapersInside($horizon) as $driver) {
            $employee = $driver->employee;
            $name = $employee->name ?? 'Driver #'.$driver->id;

            if ($driver->licence_expires_on !== null) {
                $licence = $this->candidate(
                    driver: $driver,
                    name: $name,
                    family: 'licence',
                    type: AlertType::DocExpiry,
                    paper: 'driver licence',
                    expiresOn: CarbonImmutable::instance($driver->licence_expires_on)->startOfDay(),
                    thresholds: $thresholds,
                    businessToday: CarbonImmutable::instance($businessToday)->startOfDay(),
                );

                if ($licence !== null) {
                    yield $licence;
                }
            }

            if ($driver->permit_expires_on !== null) {
                $permit = $this->candidate(
                    driver: $driver,
                    name: $name,
                    family: 'permit',
                    type: AlertType::PermitExpiry,
                    paper: 'PDP permit',
                    expiresOn: CarbonImmutable::instance($driver->permit_expires_on)->startOfDay(),
                    thresholds: $thresholds,
                    businessToday: CarbonImmutable::instance($businessToday)->startOfDay(),
                );

                if ($permit !== null) {
                    yield $permit;
                }
            }
        }
    }

    /**
     * @return LazyCollection<int, Driver>
     */
    private function driversWithPapersInside(CarbonInterface $horizon): LazyCollection
    {
        return Driver::query()
            ->with('employee')
            ->where('status', '!=', DriverStatus::Left->value)
            ->where(function ($query) use ($horizon): void {
                $query->where(function ($licence) use ($horizon): void {
                    $licence->whereNotNull('licence_expires_on')->whereDate('licence_expires_on', '<=', $horizon);
                })->orWhere(function ($permit) use ($horizon): void {
                    $permit->whereNotNull('permit_expires_on')->whereDate('permit_expires_on', '<=', $horizon);
                });
            })
            ->orderBy('licence_expires_on')
            ->cursor();
    }

    private function candidate(
        Driver $driver,
        string $name,
        string $family,
        AlertType $type,
        string $paper,
        CarbonImmutable $expiresOn,
        ThresholdSet $thresholds,
        CarbonImmutable $businessToday,
    ): ?ReminderCandidate {
        if (! $thresholds->has($family)) {
            return null;
        }

        $matchedDays = null;
        $matchedValidity = null;

        // Tightest configured band first: a paper twelve days out is inside every window at once,
        // and the narrow one is the band that describes it.
        foreach (array_reverse($thresholds->buckets($family)) as $days) {
            $validity = Validity::derive($expiresOn, $businessToday, $days);

            if ($validity === Validity::Valid || $validity === Validity::Perpetual) {
                continue;
            }

            $matchedDays = $days;
            $matchedValidity = $validity;

            break;
        }

        if ($matchedDays === null || $matchedValidity === null) {
            return null;
        }

        return new ReminderCandidate(
            type: $type,
            severity: $this->severityFor($matchedValidity, $matchedDays),
            family: $family,
            daysBefore: $matchedDays,
            subjectType: 'driver',
            subjectId: (string) $driver->id,
            occurredOn: $businessToday,
            bucket: $expiresOn->toDateString(),
            title: sprintf(
                '%s — %s expires %s (%s)',
                $name,
                $paper,
                $expiresOn->toDateString(),
                $matchedValidity->label(),
            ),
            payload: [
                'driver_id' => $driver->id,
                'employee_id' => $driver->employee_id,
                'employee_no' => $driver->employee?->employee_no,
                'paper' => $family,
                'expires_on' => $expiresOn->toDateString(),
                'validity' => $matchedValidity->value,
                'band_days' => $matchedDays,
                'days_until_expiry' => Validity::daysUntil($expiresOn, $businessToday),
            ],
        );
    }

    private function severityFor(Validity $validity, int $days): AlertSeverity
    {
        if ($validity === Validity::Expired) {
            return AlertSeverity::Critical;
        }

        return $days <= self::ImminentTighteningDays ? AlertSeverity::Warning : AlertSeverity::Info;
    }
}
