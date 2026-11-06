<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Data;

use App\Domain\Fleet\FuelType;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One fuel slip as the purchase screen hands it over (FUEL_PURCHASE).
 *
 * The amount is the figure **as stated on the slip** — a ZAR receipt at a Beitbridge pump is ZAR —
 * and `rate` is the one conversion that makes it base money. `litresX100` is the spec's scale, so
 * the form's "45.32" arrives as 4532 and the row's arithmetic stays integer.
 *
 * `bulkTankId` is where the delivery went when it is not a slip the trucks draw from: a purchase
 * naming a tank becomes stock the moment it is recorded, and its litres are no
 * longer a slip an issue can drain.
 *
 * `fuelType` is the product the pump sold — the slip's own fact, because a station slip names no
 * truck. The per-type price in settings is only what the form starts at; the price here is what
 * gets recorded.
 */
final readonly class FuelPurchaseDto
{
    public function __construct(
        public Money $amount,
        public string $rate,
        public FxRateSource $rateSource,
        public DateTimeInterface|CarbonImmutable $rateDate,
        public DateTimeInterface|CarbonImmutable $purchasedOn,
        public int $litresX100,
        public int $pricePerLitreMinor,
        public FuelType $fuelType,
        public ?int $stationId = null,
        public ?int $cardId = null,
        public ?string $invoiceNo = null,
        public ?string $fxRef = null,
        public ?string $sourceUuid = null,
        public ?int $bulkTankId = null,
    ) {
        if ($this->litresX100 <= 0) {
            throw new InvalidArgumentException('A purchase needs a positive litre count.');
        }

        if ($this->pricePerLitreMinor <= 0) {
            throw new InvalidArgumentException('A purchase needs a positive price per litre.');
        }

        if ($this->amount->minorUnits <= 0) {
            throw new InvalidArgumentException('A purchase needs a positive amount.');
        }

        // The slip ties to itself: the station states litres and a price, and the amount is their
        // product. Catching the disagreement here names the two figures; the table's CHECK catches
        // anyone who writes past the Action.
        $expected = intdiv($this->litresX100 * $this->pricePerLitreMinor + 50, 100);

        if ($expected !== $this->amount->minorUnits) {
            throw new InvalidArgumentException(sprintf(
                '%s litres × %s per litre is %s, not %s. Correct the slip or the price.',
                number_format($this->litresX100 / 100, 2, '.', ''),
                Money::ofMinor($this->pricePerLitreMinor, $this->amount->currencyCode)->format(),
                Money::ofMinor($expected, $this->amount->currencyCode)->format(),
                $this->amount->format(),
            ));
        }
    }
}
