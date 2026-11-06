<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Policies;

use App\Domain\Maintenance\Models\Part;
use App\Models\User;

/**
 * Who may read and maintain the parts catalogue ("Work orders / Parts stock").
 *
 * Workshop Manager and Stores Clerk hold `parts.manage`; the Owner holds everything; the
 * Operations Manager and Accountant read. Deleting a catalogue row is not a path — a part that is
 * no longer stocked is retired with `active = false`, because work-order lines will name it.
 */
final class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('parts.view');
    }

    public function view(User $user, Part $part): bool
    {
        return $part->company_id === $user->company_id
            && $user->can('parts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('parts.manage');
    }

    public function update(User $user, Part $part): bool
    {
        return $part->company_id === $user->company_id
            && $user->can('parts.manage');
    }

    public function delete(User $user, Part $part): bool
    {
        return false;
    }
}
