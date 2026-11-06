<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Operations\TripCostPayment;
use App\Domain\Operations\TripCostType;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One direct cost as a screen or an upstream Action hands it over (TRIP_COST).
 *
 * The amount is the figure **as stated** — the fuel slip's USD, the toll's BWP — and `rate` is the
 * one conversion that turns it into the base figure. Keeping the rate on the DTO rather than inside
 * the Action means the caller has to say where the rate came from: The `fx_rate_source` is the
 * difference between a number somebody typed and a figure the bank reported.
 *
 * `sourceType`/`sourceId` are optional and name the upstream record a retried capture belongs to
 * (a border crossing, a fuel issue). When both are present the Action returns the existing cost
 * instead of posting a second one.
 */
final readonly class TripCostDto
{
    public function __construct(
        public TripCostType $type,
        public Money $amount,
        public string $rate,
        public FxRateSource $rateSource,
        public DateTimeInterface|CarbonImmutable $rateDate,
        public DateTimeInterface|CarbonImmutable $occurredOn,
        public TripCostPayment $paidWith = TripCostPayment::PettyCash,
        public ?string $description = null,
        public ?string $fxRef = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {}
}
