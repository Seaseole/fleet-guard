<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Policies;

use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Models\User;

/**
 * Who may rehearse and who may commit (§15.2 Phase 0.5, §9.3).
 *
 * Two permissions rather than one because uploading a legacy file and posting the opening
 * balances are different acts: the accountant validates the file, the Owner alone moves the
 * ledger. `cutover.post` is also a re-auth ability in the catalogue; the re-authentication step
 * itself is HTTP middleware/session policy, not something a policy can decide.
 */
final class CutoverBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cutover.import');
    }

    public function view(User $user, CutoverBatch $batch): bool
    {
        // The global scope already keeps another company's rows out of reach; this is the
        // authorisation half, kept explicit so a `withoutCompanyScope` read cannot inherit a pass.
        return $batch->company_id === $user->company_id
            && $user->can('cutover.import');
    }

    public function create(User $user): bool
    {
        return $user->can('cutover.import');
    }

    /**
     * §6.4: a posted batch is corrected by reversal, never by editing the upload. There is no
     * update path — the Actions own every status column.
     */
    public function update(User $user, CutoverBatch $batch): bool
    {
        return false;
    }

    public function delete(User $user, CutoverBatch $batch): bool
    {
        return false;
    }

    public function post(User $user, CutoverBatch $batch): bool
    {
        return $batch->company_id === $user->company_id
            && $user->can('cutover.post');
    }

    /**
     * Applying a master file writes records into another module, so it is the Owner's act for the
     * same reason posting is: one person chooses what the legacy system puts on the books. Using
     * `cutover.post` rather than each module's `*.manage` also keeps the re-auth rule in one place.
     */
    public function apply(User $user, CutoverBatch $batch): bool
    {
        return $batch->company_id === $user->company_id
            && $user->can('cutover.post');
    }

    public function reverse(User $user, CutoverBatch $batch): bool
    {
        return $batch->company_id === $user->company_id
            && $user->can('cutover.post');
    }
}
