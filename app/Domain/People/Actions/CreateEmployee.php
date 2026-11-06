<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Adds a person to the books (D1).
 *
 * The ordering is the point: the national ID exists as plaintext only inside the DTO, is turned
 * into the trio by {@see Employee::changeNationalId()} before the row is saved, and is never
 * assigned to a mass-fill attribute that an exception or a validation error could print.
 */
final class CreateEmployee
{
    public function __invoke(EmployeeDto $dto): Employee
    {
        return DB::transaction(function () use ($dto): Employee {
            $employee = new Employee([
                'employee_no' => $dto->employeeNo,
                'name' => $dto->name,
                'role_or_grade' => $dto->roleOrGrade,
                'hired_on' => $dto->hiredOn,
                'left_on' => $dto->leftOn,
                'gross_salary_base' => $dto->grossSalaryBase,
                'pay_frequency' => $dto->payFrequency,
                'active' => $dto->active,
            ]);

            if (! $dto->preservesMaskedNationalId()) {
                $employee->changeNationalId($dto->nationalId);
            }

            $employee->save();

            return $employee;
        });
    }
}
