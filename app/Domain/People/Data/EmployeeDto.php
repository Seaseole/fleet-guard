<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use InvalidArgumentException;

/**
 * The validated input behind the employee Actions (D1).
 *
 * `nationalId` is plaintext only for the length of the request; the Action turns it into the §9.5
 * trio and the DTO is discarded. An edit that posted the mask back unchanged must not re-encrypt
 * it — {@see preservesMaskedNationalId()} is the same guard as the bank-account form, and for the
 * same reason: the mask is not the secret.
 */
final readonly class EmployeeDto
{
    public const PAY_FREQUENCIES = ['monthly', 'fortnightly'];

    public function __construct(
        public string $employeeNo,
        public string $name,
        public string $hiredOn,
        public int $grossSalaryBase = 0,
        public string $payFrequency = 'monthly',
        public ?string $nationalId = null,
        public ?string $roleOrGrade = null,
        public ?string $leftOn = null,
        public bool $active = true,
    ) {
        if ($employeeNo === '' || $name === '') {
            throw new InvalidArgumentException('An employee needs a number and a name.');
        }

        if (! in_array($payFrequency, self::PAY_FREQUENCIES, true)) {
            throw new InvalidArgumentException("Pay frequency '{$payFrequency}' is not one of ".implode(', ', self::PAY_FREQUENCIES).'.');
        }

        if ($grossSalaryBase < 0) {
            throw new InvalidArgumentException('Gross salary is a non-negative amount in minor units.');
        }

        if ($leftOn !== null && $leftOn < $hiredOn) {
            throw new InvalidArgumentException('A leaving date cannot fall before the hire date.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            employeeNo: trim((string) $input['employee_no']),
            name: trim((string) $input['name']),
            hiredOn: (string) $input['hired_on'],
            grossSalaryBase: (int) ($input['gross_salary_base'] ?? 0),
            payFrequency: (string) ($input['pay_frequency'] ?? 'monthly'),
            nationalId: self::optionalString($input['national_id'] ?? null),
            roleOrGrade: self::optionalString($input['role_or_grade'] ?? null),
            leftOn: self::optionalString($input['left_on'] ?? null),
            active: (bool) ($input['active'] ?? true),
        );
    }

    public function preservesMaskedNationalId(): bool
    {
        return $this->nationalId === null || str_contains($this->nationalId, '•');
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
