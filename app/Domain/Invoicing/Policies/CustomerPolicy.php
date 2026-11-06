<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Policies;

use App\Domain\Invoicing\Models\Customer;
use App\Models\User;

/**
 * Who may see and change a customer ("Invoicing").
 *
 * Reading is wider than writing: a Dispatcher holds `customers.view` because a job names the party
 * paying for it, but only the Owner and the Accountant hold `customers.manage`. Credit terms are a
 * field, not a row — they render behind `seeCustomerTerms`, so this policy never asks about money.
 */
final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $customer->company_id === $user->company_id
            && $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $customer->company_id === $user->company_id
            && $user->can('customers.manage');
    }

    /**
     * A customer is never deleted: their name is on issued invoices, and the gap-free promise
     * includes the paper trail. Leaving the trade is `active = false`, which keeps history readable.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
