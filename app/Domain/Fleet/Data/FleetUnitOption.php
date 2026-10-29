<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\FleetStatus;

/**
 * A physical unit as another module may see one — §13 D5's read model for the fleet.
 *
 * Operations names a truck, a trailer and their papers on every trip, and may not import `Truck` or
 * `Trailer` (§3.1 D5). This is the whole contract: the id, the label a screen prints, the status the
 * dispatch gate asks about, the capacity a load is measured against and the odometer a departure is
 * checked from. Nothing money-shaped is here, because a trip does not get to read what a truck cost.
 */
final readonly class FleetUnitOption
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $label,
        public FleetStatus $status,
        public ?float $capacityTonnes,
        public ?int $odometerKm,
        public ?float $engineHours,
        public bool $active,
    ) {}

    /** §10.2's first gate: only an available unit may be named on a dispatch. */
    public function mayBeDispatched(): bool
    {
        return $this->status->mayBeDispatched() && $this->active;
    }

    /** §10.2's loading gate: tonnage may not exceed what the unit is licensed to carry. */
    public function carries(float $tonnes): bool
    {
        return $this->capacityTonnes !== null && $tonnes <= $this->capacityTonnes;
    }

    /** §4.3's monotonic rule, asked of the projection rather than of a reading. */
    public function odometerReaches(int $km): bool
    {
        return $this->odometerKm === null || $km >= $this->odometerKm;
    }
}
