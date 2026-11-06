<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\Actions\AssignVehicle;
use InvalidArgumentException;

/**
 * The validated input behind {@see AssignVehicle} (D1).
 *
 * `fromAt` is a datetime string because the office records a time of day ("he took it at 06:30"),
 * not just a date: overlap checks operate on the interval, and a same-day handover is two
 * assignments.
 *
 * `tripId` is the trip the vehicle is being held for. It arrived with Phase 2 and is deliberately
 * nullable: a workshop loaner or an owner's errand is a real assignment with no job behind it, and
 * forcing one would invent a trip to satisfy a column. When it *is* set it is the trip's own ULID —
 * no FK, because the assignments predate the trips table and the reference is by id alone.
 */
final readonly class AssignmentDto
{
    public function __construct(
        public int $truckId,
        public string $fromAt,
        public ?int $trailerId = null,
        public ?int $driverId = null,
        public ?string $reason = null,
        public ?string $tripId = null,
    ) {
        if ($truckId <= 0) {
            throw new InvalidArgumentException('An assignment needs a truck.');
        }

        if (trim($fromAt) === '') {
            throw new InvalidArgumentException('An assignment needs a start time.');
        }

        if ($trailerId !== null && $trailerId <= 0) {
            throw new InvalidArgumentException('A trailer id must be a positive number when one is given.');
        }

        if ($driverId !== null && $driverId <= 0) {
            throw new InvalidArgumentException('A driver id must be a positive number when one is given.');
        }

        if ($tripId !== null && trim($tripId) === '') {
            throw new InvalidArgumentException('A trip reference is either a trip id or absent — never blank.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            truckId: (int) $input['truck_id'],
            fromAt: (string) $input['from_at'],
            trailerId: isset($input['trailer_id']) && $input['trailer_id'] !== '' ? (int) $input['trailer_id'] : null,
            driverId: isset($input['driver_id']) && $input['driver_id'] !== '' ? (int) $input['driver_id'] : null,
            reason: is_string($input['reason'] ?? null) && trim($input['reason']) !== '' ? trim($input['reason']) : null,
        );
    }
}
