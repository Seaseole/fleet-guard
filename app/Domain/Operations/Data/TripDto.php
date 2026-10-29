<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Operations\Support\DispatchGate;
use InvalidArgumentException;

/**
 * A trip as the planner names it (§4.1 TRIP).
 *
 * The equipment and crew are chosen at planning and *tested* at dispatch (§10.2 puts the papers
 * checks on `planned → assigned`), so this DTO accepts an unavailable truck or a lapsed driver — the
 * plan is a statement of intent, and refusing it here would hide the reason the dispatcher needs to
 * read. The refusal belongs to {@see DispatchGate}.
 */
final readonly class TripDto
{
    public function __construct(
        public string $jobId,
        public int $truckId,
        public int $driverId,
        public string $plannedDepartureAt,
        public ?int $trailerId = null,
        public ?int $supercargoEmployeeId = null,
    ) {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('A trip executes a job (§4.1); it cannot exist without one.');
        }

        if ($truckId <= 0) {
            throw new InvalidArgumentException('A trip needs a truck.');
        }

        if ($driverId <= 0) {
            throw new InvalidArgumentException('A trip needs a driver — §10.2 checks that driver\'s papers before it leaves.');
        }

        if (trim($plannedDepartureAt) === '') {
            throw new InvalidArgumentException('A trip needs a planned departure; the date is what the licence check is measured on.');
        }

        if ($trailerId !== null && $trailerId <= 0) {
            throw new InvalidArgumentException('A trailer id cannot be zero — leave it unset for a truck-only leg.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            jobId: (string) ($input['job_id'] ?? ''),
            truckId: (int) ($input['truck_id'] ?? 0),
            driverId: (int) ($input['driver_id'] ?? 0),
            plannedDepartureAt: (string) ($input['planned_departure_at'] ?? ''),
            trailerId: isset($input['trailer_id']) && (int) $input['trailer_id'] > 0 ? (int) $input['trailer_id'] : null,
            supercargoEmployeeId: isset($input['supercargo_employee_id']) && (int) $input['supercargo_employee_id'] > 0
                ? (int) $input['supercargo_employee_id']
                : null,
        );
    }
}
