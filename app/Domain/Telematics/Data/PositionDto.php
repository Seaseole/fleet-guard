<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Data;

use Carbon\CarbonImmutable;

/**
 * One normalised GPS fix — the adapter's output and the ingest Action's input (GPS_POSITION).
 *
 * The DTO is already provider-agnostic: `providerMessageId` is the provider's own dedupe key, and
 * everything else is the column set the map and the reports read. `raw` keeps the provider's message
 * verbatim for the argument about a wrong fix, and is stored as JSON on the row.
 *
 * Coordinates are floats, not money and not decimals with a fixed scale in PHP: the database's
 * `decimal(10,7)` is what fixes the precision, and a float here is what a bearing calculation needs.
 */
final readonly class PositionDto
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $providerMessageId,
        public int $truckId,
        public ?string $tripId,
        public CarbonImmutable $fixedAt,
        public float $lat,
        public float $lng,
        public ?int $speedKph = null,
        public ?int $headingDeg = null,
        public ?int $odometerKm = null,
        public ?int $accuracyM = null,
        public array $raw = [],
    ) {}
}
