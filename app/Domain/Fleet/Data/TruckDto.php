<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\FleetStatus;
use InvalidArgumentException;

/**
 * The validated input behind the truck Actions (D1).
 *
 * Amounts arrive as integer minor units; the screen converts what a human typed. Physical quantities
 * arrive as floats because they are decimals in the database by design (`capacity_tonnes_10_2`).
 */
final readonly class TruckDto
{
    /**
     * @param  array<string, mixed>|null  $complianceFlags
     */
    public function __construct(
        public string $registrationNo,
        public ?string $fleetNo = null,
        public ?string $vin = null,
        public ?string $make = null,
        public ?string $model = null,
        public ?int $year = null,
        public ?float $capacityTonnes = null,
        public int $odometerKm = 0,
        public ?float $engineHours = null,
        public int $purchaseAmountBase = 0,
        public ?string $purchasedOn = null,
        public ?string $tyreSize = null,
        public ?int $serviceIntervalKm = null,
        public ?int $serviceIntervalHours = null,
        public ?int $serviceIntervalMonths = null,
        public string $fuelType = 'diesel',
        public ?int $mainTankLitres = null,
        public ?int $auxTankLitres = null,
        public ?float $expectedConsumptionPer100 = null,
        public ?array $complianceFlags = null,
        public bool $active = true,
        public FleetStatus $initialStatus = FleetStatus::Available,
    ) {
        if (trim($registrationNo) === '') {
            throw new InvalidArgumentException('A truck needs a registration number.');
        }

        foreach (['odometer km' => $odometerKm, 'purchase amount' => $purchaseAmountBase] as $label => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(ucfirst($label).' cannot be negative.');
            }
        }

        foreach (['capacity' => $capacityTonnes, 'engine hours' => $engineHours, 'expected consumption' => $expectedConsumptionPer100] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException(ucfirst($label).' cannot be negative.');
            }
        }

        if ($year !== null && ($year < 1950 || $year > 2100)) {
            throw new InvalidArgumentException('A model year outside 1950–2100 is almost certainly a typo.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            registrationNo: trim((string) $input['registration_no']),
            fleetNo: self::optionalString($input['fleet_no'] ?? null),
            vin: self::optionalString($input['vin'] ?? null),
            make: self::optionalString($input['make'] ?? null),
            model: self::optionalString($input['model'] ?? null),
            year: self::optionalInt($input['year'] ?? null),
            capacityTonnes: self::optionalFloat($input['capacity_tonnes'] ?? null),
            odometerKm: (int) ($input['odometer_km'] ?? 0),
            engineHours: self::optionalFloat($input['engine_hours'] ?? null),
            purchaseAmountBase: (int) ($input['purchase_amount_base'] ?? 0),
            purchasedOn: self::optionalString($input['purchased_on'] ?? null),
            tyreSize: self::optionalString($input['tyre_size'] ?? null),
            serviceIntervalKm: self::optionalInt($input['service_interval_km'] ?? null),
            serviceIntervalHours: self::optionalInt($input['service_interval_hours'] ?? null),
            serviceIntervalMonths: self::optionalInt($input['service_interval_months'] ?? null),
            fuelType: (string) ($input['fuel_type'] ?? 'diesel'),
            mainTankLitres: self::optionalInt($input['main_tank_litres'] ?? null),
            auxTankLitres: self::optionalInt($input['aux_tank_litres'] ?? null),
            expectedConsumptionPer100: self::optionalFloat($input['expected_consumption_per_100'] ?? null),
            complianceFlags: is_array($input['compliance_flags'] ?? null) ? $input['compliance_flags'] : null,
            active: (bool) ($input['active'] ?? true),
            initialStatus: FleetStatus::tryFrom((string) ($input['status'] ?? FleetStatus::Available->value)) ?? FleetStatus::Available,
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function optionalFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
