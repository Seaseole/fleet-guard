<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Fleet\Plausibility;
use InvalidArgumentException;

/**
 * The validated input behind {@see RecordOdometerReading} (D1).
 *
 * `plausibility` is optional on purpose: the Action decides `suspect` when a reading runs backwards,
 * and a caller that could always override that would make the check decorative.
 */
final readonly class OdometerReadingDto
{
    public function __construct(
        public int $truckId,
        public int $odometerKm,
        public string $readOn,
        public OdometerSource $source = OdometerSource::Manual,
        public ?float $engineHours = null,
        public ?Plausibility $plausibility = null,
        public ?string $tripId = null,
    ) {
        if ($truckId <= 0) {
            throw new InvalidArgumentException('A reading needs a truck.');
        }

        if ($odometerKm < 0) {
            throw new InvalidArgumentException('An odometer reading cannot be negative.');
        }

        if ($engineHours !== null && $engineHours < 0) {
            throw new InvalidArgumentException('Engine hours cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            truckId: (int) $input['truck_id'],
            odometerKm: (int) $input['odometer_km'],
            readOn: (string) $input['read_on'],
            source: OdometerSource::tryFrom((string) ($input['source'] ?? OdometerSource::Manual->value)) ?? OdometerSource::Manual,
            engineHours: is_numeric($input['engine_hours'] ?? null) ? (float) $input['engine_hours'] : null,
            plausibility: isset($input['plausibility']) ? Plausibility::tryFrom((string) $input['plausibility']) : null,
            tripId: is_string($input['trip_id'] ?? null) && $input['trip_id'] !== '' ? $input['trip_id'] : null,
        );
    }
}
