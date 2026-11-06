<?php

declare(strict_types=1);

namespace App\Domain\Operations\Data;

use App\Domain\Operations\TripStatus;
use Carbon\CarbonImmutable;

/**
 * A trip that is on the road, as the Telematics module is allowed to see it (D5).
 *
 * The live map needs the trip number, the corridor and the two points to draw a line between; it does
 * not need the customer, the money or the crew, and naming the `Trip` model from another module would
 * make every map read a trip read. This DTO is the narrow read model `TripsInMotion` returns.
 */
final readonly class TripInMotion
{
    public function __construct(
        public string $id,
        public string $tripNo,
        public TripStatus $status,
        public int $truckId,
        public string $truckLabel,
        public ?string $routeCode,
        public ?string $origin,
        public ?string $destination,
        public ?float $distanceKm,
        public ?int $odometerStartKm,
        public ?CarbonImmutable $departedAt,
    ) {}
}
