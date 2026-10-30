<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Alerts;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Alerts\Sources\DocumentExpirySource;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\ServiceDueState;
use App\Domain\Maintenance\ServiceScheduleKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

/**
 * §14.1's `service_due` source — the nightly voice of {@see ServiceSchedule::stateOn()}.
 *
 * The due state is derived (`ok | due_soon | overdue | blocked`), so this source asks the schedule the
 * question once a night and turns the answer into a candidate. It writes nothing to the schedule: a
 * job that stored "overdue" would be a snapshot of last night printed as the present, which is the
 * defect §10.8's "`expired` is not a transition" sentence exists to prevent.
 *
 * **One alert per schedule per due point.** The cooldown bucket is the due point itself
 * (`km:123450`, `date:2026-11-14`), not the warning band: a truck three weeks from a service is
 * inside every configured rung at once, and a key per rung would put four open rows on one problem.
 * When the service is done the due point moves, which is a new instance and a new alert — the same
 * argument {@see DocumentExpirySource} makes for a renewal date.
 *
 * **The `service` threshold family is a calendar ladder, and a counter has no calendar.** §14.1's
 * thresholds are "N days before", and for a `date` schedule that is exactly what the days remaining
 * are. A km or engine-hours schedule is due at a figure, not a date, so it uses the tightest
 * configured rung: the schedule's own window (config `fleet.maintenance.due_soon_*`) decides *when* a
 * warning exists, and the rung decides *who* hears about it. An Owner who deletes a row still removes
 * the reminder, because the rung has to exist for a candidate to be delivered at all.
 *
 * `blocked` and `condition` schedules are skipped: a truck that is sold or out of service cannot have
 * its service done, and a condition-based rule has no due point to be due at. §14.1's register is only
 * worth reading if it does not chase work that cannot happen.
 */
final class ServiceDueSource implements ReminderSource
{
    public function key(): string
    {
        return 'service';
    }

    /**
     * §14.1's ladder, one family shared by the three counter/date kinds, addressed to the two seats
     * that can actually book a workshop slot.
     *
     * @return list<Threshold>
     */
    public function defaultThresholds(): array
    {
        /** @var list<int> $buckets */
        $buckets = (array) config('fleet.reminders.default_buckets', [60, 30, 14, 7]);

        $defaults = [];

        foreach ($buckets as $days) {
            $defaults[] = new Threshold('service', (int) $days, ['workshop_manager', 'operations_manager']);
        }

        return $defaults;
    }

    /**
     * @return iterable<ReminderCandidate>
     */
    public function candidates(ThresholdSet $thresholds, CarbonInterface $businessToday): iterable
    {
        if (! $thresholds->has('service')) {
            return [];
        }

        /** @var list<int> $buckets furthest horizon first, from ThresholdSet::buckets() */
        $buckets = $thresholds->buckets('service');

        if ($buckets === []) {
            return [];
        }

        $today = CarbonImmutable::instance($businessToday)->startOfDay();

        /** @var ServiceSchedule $schedule */
        foreach ($this->liveSchedules() as $schedule) {
            $state = $schedule->stateOn($today);

            if (! $state->needsAttention()) {
                continue;
            }

            $truck = $schedule->truck;

            if ($truck === null) {
                continue;
            }

            $remaining = $schedule->remainingOn($truck, $today);
            $band = $this->bandFor($schedule, $remaining, $buckets);
            $overdue = $state === ServiceDueState::Overdue;

            yield new ReminderCandidate(
                type: AlertType::ServiceDue,
                severity: $overdue ? AlertSeverity::Critical : AlertSeverity::Warning,
                family: 'service',
                daysBefore: $band,
                subjectType: 'service_schedule',
                subjectId: (string) $schedule->id,
                occurredOn: $today,
                bucket: $this->bucketFor($schedule),
                title: $overdue
                    ? sprintf('%s — %s overdue by %s', $truck->label(), $schedule->name, $this->figure($schedule->kind, abs((int) $remaining)))
                    : sprintf('%s — %s due in %s', $truck->label(), $schedule->name, $this->figure($schedule->kind, (int) $remaining)),
                payload: [
                    'service_schedule_id' => $schedule->id,
                    'truck_id' => $truck->id,
                    'truck' => $truck->label(),
                    'schedule' => $schedule->name,
                    'kind' => $schedule->kind->value,
                    'state' => $state->value,
                    'due_point' => $schedule->dueLabel(),
                    'remaining' => $schedule->remainingLabel($today),
                    'interval' => $schedule->intervalLabel(),
                    'band_days' => $band,
                ],
            );
        }
    }

    /**
     * @return LazyCollection<int, ServiceSchedule>
     */
    private function liveSchedules(): LazyCollection
    {
        return ServiceSchedule::query()
            ->where('active', true)
            ->where('kind', '!=', ServiceScheduleKind::Condition->value)
            ->with('truck')
            ->orderBy('id')
            ->cursor();
    }

    /**
     * The configured rung this candidate is delivered on.
     *
     * A date schedule picks the tightest rung it is inside — the same reading `DocumentExpirySource`
     * takes, so a service ten days out answers with the 14-day row rather than the 60. A counter
     * schedule has no day figure, and everything overdue is past every rung, so both use the tightest
     * configured rung.
     *
     * @param  list<int>  $buckets  furthest horizon first
     */
    private function bandFor(ServiceSchedule $schedule, ?int $remaining, array $buckets): int
    {
        $tightest = min($buckets);

        if ($schedule->kind !== ServiceScheduleKind::Date || $remaining === null || $remaining < 0) {
            return $tightest;
        }

        foreach (array_reverse($buckets) as $days) {
            if ($remaining <= $days) {
                return $days;
            }
        }

        return $tightest;
    }

    /** The due-point instance: it changes when the service is done, which is what makes the alert repeat. */
    private function bucketFor(ServiceSchedule $schedule): string
    {
        return match ($schedule->kind) {
            ServiceScheduleKind::Km => 'km:'.(int) $schedule->next_due_at_value,
            ServiceScheduleKind::Hours => 'hours:'.(int) $schedule->next_due_at_value,
            ServiceScheduleKind::Date => 'date:'.($schedule->next_due_on?->toDateString() ?? '-'),
            ServiceScheduleKind::Condition => 'condition',
        };
    }

    private function figure(ServiceScheduleKind $kind, int $value): string
    {
        return match ($kind) {
            ServiceScheduleKind::Km => number_format($value).' km',
            ServiceScheduleKind::Hours => number_format($value / 100, 2, '.', '').' hours',
            ServiceScheduleKind::Date => $value.' days',
            ServiceScheduleKind::Condition => '—',
        };
    }
}
