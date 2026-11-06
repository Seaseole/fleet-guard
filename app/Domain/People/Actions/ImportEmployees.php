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
use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\Models\Employee;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\SecretMask;
use InvalidArgumentException;

/**
 * Phase 1 — the employee master file.
 *
 * The national ID never waits in the batch as plaintext. A CSV file on an accountant's laptop is
 * exactly the copy exists to retire, so the cell is encrypted with {@see FieldCipher} as the
 * row is validated; only the mask is printable, and the ciphertext is decrypted for the moment the
 * row is handed to {@see CreateEmployee}/{@see UpdateEmployee}.
 */
final class ImportEmployees implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateEmployee $create,
        private readonly UpdateEmployee $update,
        private readonly FieldCipher $cipher,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $seenNumbers = [];

        return CutoverRows::read(
            CutoverDataset::Employees,
            $contents,
            function (CutoverCells $cells) use ($companyCurrency, &$seenNumbers) {
                $errors = [];

                [$employeeNo, $error] = ImportValues::text($cells->get('employee_no'), 24, 'Employee number', true);
                ImportValues::push($errors, $error);

                [$name, $error] = ImportValues::text($cells->get('name'), 120, 'Name', true);
                ImportValues::push($errors, $error);

                [$roleOrGrade, $error] = ImportValues::text($cells->get('role_or_grade'), 80, 'Role or grade');
                ImportValues::push($errors, $error);

                [$hiredOn, $error] = ImportValues::date($cells->get('hired_on'), 'Hired on', true);
                ImportValues::push($errors, $error);

                [$leftOn, $error] = ImportValues::date($cells->get('left_on'), 'Left on');
                ImportValues::push($errors, $error);

                [$grossSalary, $error] = ImportValues::money($cells->get('gross_salary'), $companyCurrency, 'Gross salary');
                ImportValues::push($errors, $error);

                [$payFrequency, $error] = ImportValues::text($cells->get('pay_frequency'), 12, 'Pay frequency');
                ImportValues::push($errors, $error);

                [$nationalId, $error] = ImportValues::text($cells->get('national_id'), 64, 'National ID');
                ImportValues::push($errors, $error);

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($employeeNo !== null) {
                    $numberKey = ImportValues::codeKey($employeeNo);

                    if (isset($seenNumbers[$numberKey])) {
                        $errors[] = sprintf(
                            'Line %d already carries the employee number "%s".',
                            $seenNumbers[$numberKey],
                            $employeeNo,
                        );
                    } else {
                        $seenNumbers[$numberKey] = $cells->line;
                    }
                }

                $values = [
                    'employee_no' => $employeeNo,
                    'name' => $name,
                    'role_or_grade' => $roleOrGrade,
                    'hired_on' => $hiredOn,
                    'left_on' => $leftOn,
                    'gross_salary' => $grossSalary,
                    'pay_frequency' => $payFrequency === null ? 'monthly' : strtolower($payFrequency),
                    'active' => $active,
                ];

                if ($nationalId !== null) {
                    $values['national_id_encrypted'] = $this->cipher->encrypt($nationalId);
                    $values['national_id_masked'] = SecretMask::tail($nationalId);
                    $values['key_version'] = $this->cipher->currentVersion();
                }

                if ($errors === []) {
                    try {
                        EmployeeDto::fromArray(self::dtoInput($values, $nationalId));
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
            $dto = EmployeeDto::fromArray(self::dtoInput($payload, self::decrypt($payload)));
            $existing = Employee::query()->where('employee_no', $dto->employeeNo)->first();

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function dtoInput(array $payload, ?string $nationalId): array
    {
        return [
            'employee_no' => $payload['employee_no'] ?? '',
            'name' => $payload['name'] ?? '',
            'hired_on' => $payload['hired_on'] ?? '',
            'gross_salary_base' => $payload['gross_salary'] ?? 0,
            'pay_frequency' => $payload['pay_frequency'] ?? 'monthly',
            'national_id' => $nationalId,
            'role_or_grade' => $payload['role_or_grade'] ?? null,
            'left_on' => $payload['left_on'] ?? null,
            'active' => $payload['active'] ?? true,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function decrypt(array $payload): ?string
    {
        $encrypted = $payload['national_id_encrypted'] ?? null;
        $version = $payload['key_version'] ?? null;

        if (! is_string($encrypted) || $encrypted === '' || ! is_string($version) || $version === '') {
            return null;
        }

        return $this->cipher->decrypt($encrypted, $version);
    }
}
