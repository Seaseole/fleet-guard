<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Policies;

use App\Domain\Accounting\Models\Loan;
use App\Models\User;

/**
 * Who may see and manage the financing book ("Loans & instalments": Accountant and Owner
 * full, Operations Manager read). The catalogue already ships `loans.view`/`loans.manage` and
 * the roles assign them — this policy just says what both halves mean on a row: reading is a
 * permission, everything that moves money or terms is the manage half, and no role deletes a
 * loan because a facility is corrected by postings, never by losing its paper.
 */
final class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('loans.view');
    }

    public function view(User $user, Loan $loan): bool
    {
        return $loan->company_id === $user->company_id && $user->can('loans.view');
    }

    public function create(User $user): bool
    {
        return $user->can('loans.manage');
    }

    public function update(User $user, Loan $loan): bool
    {
        return $loan->company_id === $user->company_id
            && $loan->status->isEditable()
            && $user->can('loans.manage');
    }

    public function manage(User $user, Loan $loan): bool
    {
        return $loan->company_id === $user->company_id && $user->can('loans.manage');
    }

    public function delete(User $user, Loan $loan): bool
    {
        return false;
    }
}
