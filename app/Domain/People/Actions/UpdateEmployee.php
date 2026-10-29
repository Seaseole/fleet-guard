<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Data\EmployeeDto;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Edits an employee's master details (§4.3, D1).
 *
 * A blank national-ID field means "leave what is on file", not "erase it" — otherwise every
 * ordinary edit of a phone number would silently drop the only stored identity record. Clearing
 * the field deliberately is a separate decision this phase does not offer.
 */
final class UpdateEmployee
{
    public function __invoke(Employee $employee, EmployeeDto $dto): Employee
    {
        return DB::transaction(function () use ($employee, $dto): Employee {
            $employee->fill([
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
