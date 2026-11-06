<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Data\ServiceScheduleDto;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\ServiceScheduleKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Creates or edits a service schedule.
 *
 * The next due point is always recomputed here rather than typed by a person: a schedule whose due
 * figure disagrees with its own interval is one the register cannot explain, and the daily job would
 * either nag early or stay silent late. When the schedule has never been done, the base is the truck
 * as it stands today — its odometer, its engine hours, today's date — so a new rule on an old truck
 * starts from where the truck actually is.
 *
 * Editing a schedule is allowed even after a service has moved it; the edit is a person deciding the
 * rule changed (a different oil interval, a different tyre rotation), and the row is the rule. What
 * is *not* allowed is a second schedule with the same name on the same truck: two rows claiming
 * "engine service" would each raise a service-due alert and the register would ask twice.
 */
final class SaveServiceSchedule
{
    public function __invoke(ServiceScheduleDto $dto, ?ServiceSchedule $existing = null, ?int $actorId = null): ServiceSchedule
    {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Service schedules belong to the workshop (the design gives `work_orders.create` to it).');
        }

        return DB::transaction(function () use ($dto, $existing): ServiceSchedule {
            $truck = Truck::query()->findOrFail($dto->truckId);

            if (! $truck->active || $truck->status === FleetStatus::Sold) {
                throw new InvalidArgumentException(sprintf(
                    '%s is no longer part of the fleet; a service schedule on it would only produce reminders nobody can action.',
                    $truck->label(),
                ));
            }

            $duplicate = ServiceSchedule::query()
                ->where('truck_id', $truck->id)
                ->where('name', $dto->name)
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException(sprintf('%s already has a schedule called "%s".', $truck->label(), $dto->name));
            }

            $today = Carbon::now(CurrentCompany::timezone());
            [$lastDoneAt, $lastDoneOn, $nextDueAt, $nextDueOn] = $this->duePoints($dto, $truck, $today);

            $attributes = [
                'truck_id' => $truck->id,
                'name' => $dto->name,
                'kind' => $dto->kind,
                'interval_value' => $dto->intervalValue,
                'last_done_at_value' => $lastDoneAt,
                'last_done_on' => $lastDoneOn,
                'next_due_at_value' => $nextDueAt,
                'next_due_on' => $nextDueOn,
                'active' => $dto->active,
            ];

            if ($existing === null) {
                /** @var ServiceSchedule $schedule */
                $schedule = ServiceSchedule::create($attributes);
            } else {
                $existing->forceFill($attributes)->save();
                $schedule = $existing;
            }

            return $schedule->refresh();
        });
    }

    /**
     * @return array{0: int|null, 1: Carbon|null, 2: int|null, 3: Carbon|null}
     */
    private function duePoints(ServiceScheduleDto $dto, Truck $truck, Carbon $today): array
    {
        $interval = (int) $dto->intervalValue;

        return match ($dto->kind) {
            ServiceScheduleKind::Km => [
                $dto->lastDoneAtValue ?? $truck->odometer_km,
                null,
                ($dto->lastDoneAtValue ?? $truck->odometer_km) + $interval,
                null,
            ],
            ServiceScheduleKind::Hours => [
                $dto->lastDoneAtValue ?? (int) round(((float) ($truck->engine_hours_10_2 ?? 0)) * 100),
                null,
                ($dto->lastDoneAtValue ?? (int) round(((float) ($truck->engine_hours_10_2 ?? 0)) * 100)) + $interval,
                null,
            ],
            ServiceScheduleKind::Date => [
                null,
                $dto->lastDoneOn === null
                    ? $today->copy()->startOfDay()
                    : Carbon::instance($dto->lastDoneOn)->startOfDay(),
                null,
                ($dto->lastDoneOn === null
                    ? $today->copy()->startOfDay()
                    : Carbon::instance($dto->lastDoneOn)->startOfDay())->addDays($interval),
            ],
            ServiceScheduleKind::Condition => [null, $dto->lastDoneOn === null ? null : Carbon::instance($dto->lastDoneOn)->startOfDay(), null, null],
        };
    }
}
