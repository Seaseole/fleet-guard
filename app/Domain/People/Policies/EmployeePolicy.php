<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\People\Models\Employee;
use App\Models\User;

/**
 * Who may see and change an employee ("People").
 *
 * Reading people is deliberately wider than changing them: the Dispatcher holds `people.view`
 * because dispatch must name a driver, but only `people.manage` (Owner, Operations Manager) edits a
 * record. Salary is a field, not a row — it is rendered behind {@see FieldGate::SeePayroll}
 * by the screen, so this policy never has to ask about money.
 */
final class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('people.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $employee->company_id === $user->company_id
            && $user->can('people.view');
    }

    public function create(User $user): bool
    {
        return $user->can('people.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $employee->company_id === $user->company_id
            && $user->can('people.manage');
    }

    /**
     * People are never deleted: a payroll record that vanishes takes its history with it. Leaving is
     * `left_on` plus `active = false`, which the update path owns.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }
}
