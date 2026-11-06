<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Policies;

use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\ReconciliationStatus;
use App\Models\User;

/**
 * Who may watch and who may sign a reconciliation ("Bank & cash accounts": reading the
 * register is `bank.view`; the reconciliation is the Accountant's and the Owner's control, so
 * every mutation asks `bank.reconcile`).
 *
 * The policy also answers the *status* half of two questions the Actions re-check — mutating an
 * approved or voided run has no meaning (a signature is not an editable draft, ADR-0001's
 * discipline one layer up from the ledger), so the authorisation itself says no.
 */
final class ReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bank.view');
    }

    public function view(User $user, Reconciliation $reconciliation): bool
    {
        return $reconciliation->company_id === $user->company_id
            && $user->can('bank.view');
    }

    public function start(User $user): bool
    {
        return $user->can('bank.reconcile');
    }

    public function mutate(User $user, Reconciliation $reconciliation): bool
    {
        return $reconciliation->company_id === $user->company_id
            && $reconciliation->status === ReconciliationStatus::Open
            && $user->can('bank.reconcile');
    }

    public function approve(User $user, Reconciliation $reconciliation): bool
    {
        // Approving is signing; only an open run can be signed. Whether the figures agree is the
        // Action's refusal — the policy asks who, the maths asks whether.
        return $this->mutate($user, $reconciliation);
    }

    public function void(User $user, Reconciliation $reconciliation): bool
    {
        return $reconciliation->company_id === $user->company_id
            && $reconciliation->status !== ReconciliationStatus::Void
            && $user->can('bank.reconcile');
    }

    public function delete(User $user, Reconciliation $reconciliation): bool
    {
        return false;
    }
}
