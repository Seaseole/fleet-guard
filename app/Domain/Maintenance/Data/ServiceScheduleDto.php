<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Maintenance\Actions\SaveServiceSchedule;
use App\Domain\Maintenance\ServiceScheduleKind;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The validated input behind {@see SaveServiceSchedule} (D1).
 *
 * The interval and the "last done at" figure arrive already scaled: kilometres for a `km` schedule,
 * hundredths of an hour for an `hours` one, days for a `date` one (§5.5's scale applied to a physical
 * quantity). The Action turns that into a next due point; the DTO only refuses a shape that cannot
 * mean anything — a negative interval, a counter schedule with no counter, a condition schedule with
 * one.
 */
final readonly class ServiceScheduleDto
{
    public function __construct(
        public int $truckId,
        public string $name,
        public ServiceScheduleKind $kind,
        public ?int $intervalValue,
        public ?int $lastDoneAtValue = null,
        public ?CarbonInterface $lastDoneOn = null,
        public bool $active = true,
    ) {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A service schedule needs a name — it is what the work order and the register call the service.');
        }

        if (mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Keep the schedule name to 80 characters.');
        }

        if ($kind === ServiceScheduleKind::Condition) {
            if ($intervalValue !== null) {
                throw new InvalidArgumentException('A condition schedule has no interval: it is done when somebody says the condition is there (§4.3).');
            }

            return;
        }

        if ($intervalValue === null || $intervalValue <= 0) {
            throw new InvalidArgumentException('A service interval must be a positive number in its own unit.');
        }

        if ($lastDoneAtValue !== null && $lastDoneAtValue < 0) {
            throw new InvalidArgumentException('The last-done figure cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $interval = $input['interval_value'] ?? null;
        $lastValue = $input['last_done_at_value'] ?? null;
        $lastDate = $input['last_done_on'] ?? null;

        return new self(
            truckId: (int) $input['truck_id'],
            name: trim((string) $input['name']),
            kind: ServiceScheduleKind::from((string) $input['kind']),
            intervalValue: $interval === null || $interval === '' ? null : (int) $interval,
            lastDoneAtValue: $lastValue === null || $lastValue === '' ? null : (int) $lastValue,
            lastDoneOn: $lastDate === null || $lastDate === '' ? null : Carbon::parse((string) $lastDate),
            active: (bool) ($input['active'] ?? true),
        );
    }
}
