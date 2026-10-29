<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\People\Data\DriverDto;
use App\Domain\People\DriverStatus;
use App\Domain\People\Models\Driver;
use App\Domain\People\Models\Employee;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\SecretMask;
use InvalidArgumentException;

/**
 * §15.2 Phase 1 — the driver record attached to an employee.
 *
 * The row is matched by `employee_no`, because a driver is an employee with papers, not a separate
 * person: a file that names an employee nobody has imported is a row error, not a new employee. The
 * licence number follows the national-ID rule — encrypted at validation, decrypted only to be handed
 * to {@see CreateDriver}/{@see UpdateDriver}.
 *
 * Status is applied through {@see ChangeDriverStatus} after the fields, so the §10.1 transition table
 * still governs it. A driver whose record says `left` cannot be moved back by a file; that is a
 * rehire, which is a new employee row by design.
 */
final class ImportDrivers implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateDriver $create,
        private readonly UpdateDriver $update,
        private readonly ChangeDriverStatus $changeStatus,
        private readonly FieldCipher $cipher,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $employees = [];
        $statuses = [];

        foreach (Employee::query()->get(['id', 'employee_no']) as $employee) {
            $employees[ImportValues::codeKey($employee->employee_no)] = $employee->id;
        }

        foreach (Driver::query()->get(['employee_id', 'status']) as $driver) {
            $statuses[$driver->employee_id] = $driver->status;
        }

        $seenEmployees = [];

        return CutoverRows::read(
            CutoverDataset::Drivers,
            $contents,
            function (CutoverCells $cells) use ($employees, $statuses, $companyCurrency, &$seenEmployees) {
                $errors = [];

                [$employeeNo, $error] = ImportValues::text($cells->get('employee_no'), 24, 'Employee number', true);
                ImportValues::push($errors, $error);

                [$licenceNo, $error] = ImportValues::text($cells->get('licence_no'), 64, 'Licence number');
                ImportValues::push($errors, $error);

                [$licenceCode, $error] = ImportValues::text($cells->get('licence_code'), 10, 'Licence code');
                ImportValues::push($errors, $error);

                [$licenceExpiresOn, $error] = ImportValues::date($cells->get('licence_expires_on'), 'Licence expires on');
                ImportValues::push($errors, $error);

                [$permitExpiresOn, $error] = ImportValues::date($cells->get('permit_expires_on'), 'Permit expires on');
                ImportValues::push($errors, $error);

                [$allowance, $error] = ImportValues::money($cells->get('allowance_rate'), $companyCurrency, 'Allowance rate');
                ImportValues::push($errors, $error);

                [$fuelEfficiency, $error] = ImportValues::decimal($cells->get('fuel_efficiency_baseline'), 'Fuel efficiency baseline');
                ImportValues::push($errors, $error);

                [$kioskEnabled, $error] = ImportValues::flag($cells->get('kiosk_enabled'), 'Kiosk enabled');
                ImportValues::push($errors, $error);

                [$statusRaw, $error] = ImportValues::text($cells->get('status'), 16, 'Status');
                ImportValues::push($errors, $error);

                $status = DriverStatus::Active;

                if ($statusRaw !== null) {
                    $parsedStatus = DriverStatus::tryFrom(strtolower($statusRaw));

                    if ($parsedStatus === null) {
                        $errors[] = sprintf(
                            'Status "%s" is not one of %s.',
                            $statusRaw,
                            implode(', ', array_map(static fn (DriverStatus $case): string => $case->value, DriverStatus::cases())),
                        );
                    } else {
                        $status = $parsedStatus;
                    }
                }
                $employeeId = null;

                if ($employeeNo !== null) {
                    $numberKey = ImportValues::codeKey($employeeNo);

                    if (isset($seenEmployees[$numberKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the employee number "%s".',
                            $seenEmployees[$numberKey],
                            $employeeNo,
                        );
                    } else {
                        $seenEmployees[$numberKey] = $cells->line;
                    }

                    $employeeId = $employees[$numberKey] ?? null;

                    if ($employeeId === null) {
                        $errors[] = sprintf('There is no employee "%s" — import the employee file before its drivers.', $employeeNo);
                    } elseif (($statuses[$employeeId] ?? null) === DriverStatus::Left && $status !== DriverStatus::Left) {
                        $errors[] = 'This driver has left the company; a rehire is a new employee record, not a status change.';
                    }
                }

                $values = [
                    'employee_no' => $employeeNo,
                    'licence_code' => $licenceCode,
                    'licence_expires_on' => $licenceExpiresOn,
                    'permit_expires_on' => $permitExpiresOn,
                    'allowance_rate' => $allowance,
                    'fuel_efficiency_baseline' => $fuelEfficiency,
                    'kiosk_enabled' => $kioskEnabled,
                    'status' => $status->value,
                ];

                if ($licenceNo !== null) {
                    $values['licence_no_encrypted'] = $this->cipher->encrypt($licenceNo);
                    $values['licence_no_masked'] = SecretMask::tail($licenceNo);
                    $values['key_version'] = $this->cipher->currentVersion();
                }

                if ($errors === []) {
                    try {
                        DriverDto::fromArray(self::dtoInput($values, (int) $employeeId, $licenceNo));
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $employee = Employee::query()->where('employee_no', (string) ($payload['employee_no'] ?? ''))->firstOrFail();
            $dto = DriverDto::fromArray(self::dtoInput($payload, $employee->id, self::decrypt($payload)));
            $driver = Driver::query()->where('employee_id', $employee->id)->first();

            if ($driver === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($driver, $dto);

                if ($driver->status !== $dto->initialStatus) {
                    ($this->changeStatus)($driver, $dto->initialStatus, 'Set by the cutover import.');
                }
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function dtoInput(array $payload, int $employeeId, ?string $licenceNo): array
    {
        return [
            'employee_id' => $employeeId,
            'licence_no' => $licenceNo,
            'licence_code' => $payload['licence_code'] ?? null,
            'licence_expires_on' => $payload['licence_expires_on'] ?? null,
            'permit_expires_on' => $payload['permit_expires_on'] ?? null,
            'allowance_rate_base' => $payload['allowance_rate'] ?? 0,
            'fuel_efficiency_baseline' => $payload['fuel_efficiency_baseline'] ?? null,
            'kiosk_enabled' => $payload['kiosk_enabled'] ?? false,
            'status' => $payload['status'] ?? DriverStatus::Active->value,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function decrypt(array $payload): ?string
    {
        $encrypted = $payload['licence_no_encrypted'] ?? null;
        $version = $payload['key_version'] ?? null;

        if (! is_string($encrypted) || $encrypted === '' || ! is_string($version) || $version === '') {
            return null;
        }

        return $this->cipher->decrypt($encrypted, $version);
    }
}
