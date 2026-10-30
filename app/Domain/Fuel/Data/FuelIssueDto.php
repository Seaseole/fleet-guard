<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Data;

use App\Domain\Fuel\FuelIssueSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One fuel issue as the trip's fuel panel hands it over (§4.3 FUEL_ISSUE).
 *
 * The truck is **not** a field: an issue belongs to the trip it fuels, and the trip already names
 * the truck. A caller that could name a different one would be able to pour Trip 12's diesel into
 * Truck 7 and have both screens agree with themselves. The tank, however, is a choice — a truck has
 * a main and an auxiliary — so it is named, and the Action proves it belongs to the trip's truck.
 *
 * `purchaseId` is null only for a bulk-tank issue; the Slice-3a Action refuses those with a sentence
 * naming the slice that will accept them, rather than writing a row with no cost behind it.
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
    }
}
