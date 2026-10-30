<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Maintenance\ServiceDueState;
use App\Domain\Maintenance\ServiceScheduleKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.3 SERVICE_SCHEDULE — the rule that says when a truck is due, and when it was last seen.
 *
 * The due state is **derived here and stored nowhere** (§10.8's rule for a derived fact): the answer
 * is a function of this row, the truck's odometer/hours and today's date, and three of those four
 * change without this row being touched. A stored `state` would be a snapshot of the last time a job
 * ran, printed as though it were the present — the exact defect §10.8's "`expired` is not a
 * transition" sentence exists to prevent.
 *
 * The "last done" figures are written by the work order that does the service (a `km` schedule takes
 * the completion odometer, a `date` one the completion date), and the next due point is recomputed at
 * the same moment. Nothing else moves them: a schedule edited by hand is a person overriding the
 * rule, which is allowed, because the row is a decision and not a cache.
 *
 * @property int $id
 * @property int $company_id
 * @property int $truck_id
 * @property string $name
 * @property ServiceScheduleKind $kind
 * @property int|null $interval_value
 * @property int|null $last_done_at_value
 * @property Carbon|null $last_done_on
 * @property int|null $next_due_at_value
 * @property Carbon|null $next_due_on
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ServiceSchedule extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'truck_id',
        'name',
        'kind',
        'interval_value',
        'last_done_at_value',
        'last_done_on',
        'next_due_at_value',
        'next_due_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'truck_id' => 'integer',
            'kind' => ServiceScheduleKind::class,
            'interval_value' => 'integer',
            'last_done_at_value' => 'integer',
            'last_done_on' => 'date',
            'next_due_at_value' => 'integer',
            'next_due_on' => 'date',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Truck, $this> */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function label(): string
    {
        return $this->name.' · '.$this->truck?->label();
    }

    /**
     * Where the schedule stands on a given business day.
     *
     * A truck that has left the fleet blocks its schedules: `sold` is terminal and `out_of_service` is
     * a decision that it is not working, so a service reminder for either is a reminder nobody can
     * action. `in_workshop` is not blocked — that is precisely when the service gets done.
     */
    public function stateOn(CarbonInterface $today): ServiceDueState
    {
        if ($this->kind === ServiceScheduleKind::Condition) {
            return ServiceDueState::Ok;
        }

        $truck = $this->truck;

        if ($truck === null || ! $truck->active || $truck->status === FleetStatus::Sold || $truck->status === FleetStatus::OutOfService) {
            return ServiceDueState::Blocked;
        }

        $remaining = $this->remainingOn($truck, $today);

        if ($remaining === null) {
            return ServiceDueState::Ok;
        }

        if ($remaining < 0) {
            return ServiceDueState::Overdue;
        }

        return $remaining <= $this->warningWindow() ? ServiceDueState::DueSoon : ServiceDueState::Ok;
    }

    /** Units left before the due point: km, hundredths of an hour, or days. Negative when past it. */
    public function remainingOn(Truck $truck, CarbonInterface $today): ?int
    {
        return match ($this->kind) {
            ServiceScheduleKind::Km => $this->next_due_at_value === null
                ? null
                : $this->next_due_at_value - $truck->odometer_km,
            ServiceScheduleKind::Hours => $this->next_due_at_value === null
                ? null
                : $this->next_due_at_value - (int) round(((float) ($truck->engine_hours_10_2 ?? 0)) * 100),
            ServiceScheduleKind::Date => $this->next_due_on === null
                ? null
                : (int) $today->copy()->startOfDay()->diffInDays($this->next_due_on->copy()->startOfDay(), false),
            ServiceScheduleKind::Condition => null,
        };
    }

    /** "1,250 km", "34.00 hours", "12 days", "—" — the figure the register's remaining column prints. */
    public function remainingLabel(CarbonInterface $today): string
    {
        $truck = $this->truck;

        if ($truck === null) {
            return '—';
        }

        $remaining = $this->remainingOn($truck, $today);

        if ($remaining === null) {
            return '—';
        }

        return match ($this->kind) {
            ServiceScheduleKind::Km => number_format($remaining).' km',
            ServiceScheduleKind::Hours => number_format($remaining / 100, 2, '.', '').' hours',
            ServiceScheduleKind::Date => $remaining.' days',
            ServiceScheduleKind::Condition => '—',
        };
    }

    /**
     * Close one interval from the workshop: the service happened at this reading/day, so the next
     * due point moves one interval on.
     *
     * The figures are the completion's own, not the truck's as they stand when the screen is opened:
     * a km schedule advanced by a later odometer would credit the service with kilometres driven
     * after the vehicle left the bay, and the next reminder would arrive late by exactly that much.
     *
     * An engine-hours schedule needs the hour meter reading — there is no way to derive it from a
     * completion that only saw the odometer, and guessing would put the due point somewhere nobody
     * measured.
     */
    public function recordService(int $odometerKm, ?int $engineHoursX100, CarbonInterface $on): self
    {
        if ($this->kind === ServiceScheduleKind::Hours && $engineHoursX100 === null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is measured in engine hours; completing it needs the hour meter reading, not just the odometer.',
                $this->name,
            ));
        }

        $interval = (int) $this->interval_value;
        $day = $on->copy()->startOfDay();

        [$lastAt, $lastOn, $nextAt, $nextOn] = match ($this->kind) {
            ServiceScheduleKind::Km => [$odometerKm, null, $odometerKm + $interval, null],
            ServiceScheduleKind::Hours => [(int) $engineHoursX100, null, (int) $engineHoursX100 + $interval, null],
            ServiceScheduleKind::Date => [null, $day, null, $day->copy()->addDays($interval)],
            ServiceScheduleKind::Condition => [null, $day, null, null],
        };

        $this->forceFill([
            'last_done_at_value' => $lastAt,
            'last_done_on' => $lastOn,
            'next_due_at_value' => $nextAt,
            'next_due_on' => $nextOn,
        ])->save();

        return $this;
    }

    /** The interval stated in its own unit — "every 25,000 km" — for the schedule column. */
    public function intervalLabel(): string
    {
        if ($this->interval_value === null) {
            return 'When the condition is seen';
        }

        return match ($this->kind) {
            ServiceScheduleKind::Km => 'Every '.number_format($this->interval_value).' km',
            ServiceScheduleKind::Hours => 'Every '.number_format($this->interval_value / 100, 2, '.', '').' hours',
            ServiceScheduleKind::Date => 'Every '.$this->interval_value.' days',
            ServiceScheduleKind::Condition => 'When the condition is seen',
        };
    }

    /** The due point as words: "123,450 km", "2026-11-14". */
    public function dueLabel(): string
    {
        return match ($this->kind) {
            ServiceScheduleKind::Km => number_format((int) $this->next_due_at_value).' km',
            ServiceScheduleKind::Hours => number_format(((int) $this->next_due_at_value) / 100, 2, '.', '').' hours',
            ServiceScheduleKind::Date => $this->next_due_on?->format('d M Y') ?? '—',
            ServiceScheduleKind::Condition => '—',
        };
    }

    private function warningWindow(): int
    {
        return match ($this->kind) {
            ServiceScheduleKind::Km => (int) config('fleet.maintenance.due_soon_km', 1000),
            ServiceScheduleKind::Hours => (int) config('fleet.maintenance.due_soon_hours', 50) * 100,
            ServiceScheduleKind::Date => (int) config('fleet.maintenance.due_soon_days', 14),
            ServiceScheduleKind::Condition => 0,
        };
    }
}
