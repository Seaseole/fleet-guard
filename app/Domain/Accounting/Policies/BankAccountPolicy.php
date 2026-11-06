<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Policies;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Kernel\Identity\FieldGatePolicy;
use App\Models\User;

/**
 * Who may see and change a bank account ("Bank & cash accounts, encrypted numbers").
 *
 * Two separate questions, deliberately in two separate places: this policy answers *may you touch
 * the row*, and {@see FieldGatePolicy::seeBankDetail()} answers *may the
 * full number be rendered*. A policy that also decided the column would be the ad-hoc `@can`
 * expression the design forbids, and a gate that also decided row access would be a second owner for the
 * same rule.
 */
final class BankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bank.view');
    }

    public function view(User $user, BankAccount $account): bool
    {
        // The global scope already keeps another company's rows out of reach; this is the
        // authorisation half, kept explicit so a `withoutGlobalScope` read cannot inherit a pass.
        return $account->company_id === $user->company_id
            && $user->can('bank.view');
    }

    public function create(User $user): bool
    {
        return $user->can('bank.manage');
    }

    /**
     * Changing bank details is a `⚑` action in. The permission is checked here; the
     * re-authentication step is the `reauth` middleware that lands with task #21 and is driven by
     * `PermissionCatalogue::requiresReauth()`.
     */
    public function update(User $user, BankAccount $account): bool
    {
        return $account->company_id === $user->company_id
            && $user->can('bank.manage');
    }

    /**
     * No hard deletion of posted financial data. Until the ledger slice decides whether this
     * account has ever been posted through, closing it (`active = false`, `closed_on`) is the only
     * supported "removal", so there is no delete path to authorise.
     */
    public function delete(User $user, BankAccount $account): bool
    {
        return false;
    }
}
