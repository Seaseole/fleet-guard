<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\FleetStatus;
use InvalidArgumentException;

/**
 * The validated input behind the trailer Actions (D1).
 */
final readonly class TrailerDto
{
    public const TYPES = ['flatbed', 'tipper', 'curtainsider', 'tanker', 'lowboy'];

    public function __construct(
        public string $registrationNo,
        public string $type = 'flatbed',
        public ?string $fleetNo = null,
        public ?string $vin = null,
        public ?float $capacityTonnes = null,
        public ?string $axleConfig = null,
        public int $odometerKm = 0,
        public bool $active = true,
        public FleetStatus $initialStatus = FleetStatus::Available,
    ) {
        if (trim($registrationNo) === '') {
            throw new InvalidArgumentException('A trailer needs a registration number.');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Trailer type '{$type}' is not one of ".implode(', ', self::TYPES).'.');
        }

        if ($capacityTonnes !== null && $capacityTonnes < 0) {
            throw new InvalidArgumentException('Capacity cannot be negative.');
        }

        if ($odometerKm < 0) {
            throw new InvalidArgumentException('Odometer cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            registrationNo: trim((string) $input['registration_no']),
            type: (string) ($input['type'] ?? 'flatbed'),
            fleetNo: self::optionalString($input['fleet_no'] ?? null),
            vin: self::optionalString($input['vin'] ?? null),
            capacityTonnes: is_numeric($input['capacity_tonnes'] ?? null) ? (float) $input['capacity_tonnes'] : null,
            axleConfig: self::optionalString($input['axle_config'] ?? null),
            odometerKm: (int) ($input['odometer_km'] ?? 0),
            active: (bool) ($input['active'] ?? true),
            initialStatus: FleetStatus::tryFrom((string) ($input['status'] ?? FleetStatus::Available->value)) ?? FleetStatus::Available,
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
