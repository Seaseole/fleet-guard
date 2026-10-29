<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\DriverStatus;
use InvalidArgumentException;

/**
 * The validated input behind the driver Actions (D1).
 *
 * The licence number is the only secret here and follows the same rule as the employee's national
 * ID: null means "leave what is on file", and a posted-back mask is not a number.
 */
final readonly class DriverDto
{
    public function __construct(
        public int $employeeId,
        public ?string $licenceNo = null,
        public ?string $licenceCode = null,
        public ?string $licenceExpiresOn = null,
        public ?string $permitExpiresOn = null,
        public int $allowanceRateBase = 0,
        public ?float $fuelEfficiencyBaseline = null,
        public bool $kioskEnabled = false,
        public DriverStatus $initialStatus = DriverStatus::Active,
    ) {
        if ($employeeId <= 0) {
            throw new InvalidArgumentException('A driver must be linked to an employee.');
        }

        if ($allowanceRateBase < 0) {
            throw new InvalidArgumentException('The default allowance is a non-negative amount in minor units.');
        }

        if ($fuelEfficiencyBaseline !== null && $fuelEfficiencyBaseline < 0) {
            throw new InvalidArgumentException('Fuel efficiency is a consumption rate in litres per 100 km; it cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $baseline = $input['fuel_efficiency_baseline'] ?? null;

        return new self(
            employeeId: (int) $input['employee_id'],
            licenceNo: self::optionalString($input['licence_no'] ?? null),
            licenceCode: self::optionalString($input['licence_code'] ?? null),
            licenceExpiresOn: self::optionalString($input['licence_expires_on'] ?? null),
            permitExpiresOn: self::optionalString($input['permit_expires_on'] ?? null),
            allowanceRateBase: (int) ($input['allowance_rate_base'] ?? 0),
            fuelEfficiencyBaseline: is_numeric($baseline) ? (float) $baseline : null,
            kioskEnabled: (bool) ($input['kiosk_enabled'] ?? false),
            initialStatus: DriverStatus::tryFrom((string) ($input['status'] ?? DriverStatus::Active->value)) ?? DriverStatus::Active,
        );
    }

    public function preservesMaskedLicence(): bool
    {
        return $this->licenceNo === null || str_contains($this->licenceNo, '•');
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
