<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Operations\BorderCostKind;
use App\Domain\Operations\TripCostPayment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One charge at one gate, as the crossing screen hands it over (§4.4 BORDER_COST).
 *
 * The money side is §5.2's foreign amount plus its rate and source — the same three facts a
 * {@see TripCostDto} carries, because the Action posts through `RecordTripCost` and the ledger must
 * be told exactly what the desk was told. What is *not* here is the FX result: the Action copies the
 * posted trip cost's eight columns rather than converting a second time.
 */
final readonly class BorderCostDto
{
    public function __construct(
        public BorderCostKind $kind,
        public Money $amount,
        public string $rate,
        public FxRateSource $rateSource,
        public DateTimeInterface|CarbonImmutable $rateDate,
        public DateTimeInterface|CarbonImmutable $occurredOn,
        public TripCostPayment $paidWith = TripCostPayment::PettyCash,
        public ?string $description = null,
    ) {
        if ($amount->minorUnits <= 0) {
            throw new InvalidArgumentException('A border charge must be a positive amount.');
        }

        if ($description !== null && mb_strlen($description) > 160) {
            throw new InvalidArgumentException('Keep the charge description to 160 characters — it labels the cost, it is not the receipt.');
        }
    }
}
