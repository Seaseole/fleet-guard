<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Data;

use App\Domain\Fuel\FuelIssueSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One fuel issue as the trip's fuel panel hands it over (FUEL_ISSUE).
 *
 * The truck is **not** a field: an issue belongs to the trip it fuels, and the trip already names
 * the truck. A caller that could name a different one would be able to pour Trip 12's diesel into
 * Truck 7 and have both screens agree with themselves. The tank, however, is a choice — a truck has
 * a main and an auxiliary — so it is named, and the Action proves it belongs to the trip's truck.
 *
 * A `pump_card`, `advance` or `kiosk` issue names the slip it drains; a `bulk_tank` issue names the
 * tank it drew from instead. Exactly one of the two, which is what the table's CHECKs state and what
 * the constructor refuses here with a sentence.
 */
final readonly class FuelIssueDto
{
    public function __construct(
        public int $tankId,
        public int $litresX100,
        public int $odometerKm,
        public DateTimeInterface|CarbonImmutable $occurredOn,
        public ?string $purchaseId = null,
        public FuelIssueSource $source = FuelIssueSource::PumpCard,
        public ?string $note = null,
        public ?string $sourceUuid = null,
        public ?int $bulkTankId = null,
    ) {
        if ($this->tankId <= 0) {
            throw new InvalidArgumentException('An issue needs a tank.');
        }

        if ($this->litresX100 <= 0) {
            throw new InvalidArgumentException('An issue needs a positive litre count.');
        }

        if ($this->odometerKm < 0) {
            throw new InvalidArgumentException('An odometer reading cannot be negative.');
        }

        if ($this->source->requiresPurchase() && ($this->purchaseId === null || $this->purchaseId === '')) {
            throw new InvalidArgumentException(sprintf('A %s issue has to name the purchase it draws from.', $this->source->label()));
        }

        if ($this->source === FuelIssueSource::BulkTank && ($this->bulkTankId === null || $this->bulkTankId <= 0)) {
            throw new InvalidArgumentException('A bulk-tank issue has to name the tank it drew from.');
        }

        if ($this->source !== FuelIssueSource::BulkTank && $this->bulkTankId !== null) {
            throw new InvalidArgumentException('Only a bulk-tank issue draws from a bulk tank; a slip issue names its purchase.');
        }
    }
}
