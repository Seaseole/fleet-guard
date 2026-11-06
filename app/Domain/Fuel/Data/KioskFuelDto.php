<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What the driver's fuel screen hands over.
 *
 * Litres and price arrive as the spec's hundredths so a pump's 45.32 L × 19.75 is integer arithmetic
 * all the way to the slip's amount, and the odometer is required because the issue records the
 * reading the truck's timeline will keep. The station is optional at this boundary — a cash slip from
 * a pump nobody registered is still a slip — but the screen offers the register's stations, and the
 * purchase Action refuses a foreign id by name.
 *
 * There is no trip and no truck: the token names the trip, the trip names the truck, and a DTO that
 * could name either would be a way to pour one trip's diesel into another.
 */
final readonly class KioskFuelDto
{
    public function __construct(
        public int $tankId,
        public int $litresX100,
        public int $pricePerLitreMinor,
        public int $odometerKm,
        public DateTimeInterface|CarbonImmutable $occurredOn,
        public ?int $stationId = null,
        public ?string $invoiceNo = null,
        public ?string $note = null,
    ) {
        if ($this->tankId <= 0) {
            throw new InvalidArgumentException('A kiosk issue needs the tank the diesel went into.');
        }

        if ($this->litresX100 <= 0) {
            throw new InvalidArgumentException('A kiosk issue needs a positive litre count.');
        }

        if ($this->pricePerLitreMinor <= 0) {
            throw new InvalidArgumentException('A kiosk issue needs the price per litre the driver paid.');
        }

        if ($this->odometerKm < 0) {
            throw new InvalidArgumentException('An odometer reading cannot be negative.');
        }
    }

    /** The slip's amount: litres × price, rounded half-up once. */
    public function amountMinor(): int
    {
        return intdiv($this->litresX100 * $this->pricePerLitreMinor + 50, 100);
    }
}
