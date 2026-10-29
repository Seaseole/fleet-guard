<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Policies;

use App\Domain\Accounting\Models\Supplier;
use App\Models\User;

/**
 * Who may see and change a supplier (§9.3 "Accounting").
 *
 * `suppliers.view` is held by the Workshop Manager and the Stores Clerk as well as the money seats:
 * both of them raise expenses that name a supplier, and §9.3 gives them "C" on the expense row.
 * Editing the master record is the Accountant's and the Owner's job (`suppliers.manage`).
 */
final class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $supplier->company_id === $user->company_id
            && $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $supplier->company_id === $user->company_id
            && $user->can('suppliers.manage');
    }

    /** A supplier with bills against them is never deleted; retirement is `active = false`. */
    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
