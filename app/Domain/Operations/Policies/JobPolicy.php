<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Operations\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and change a job (§9.3 "Jobs", §10.3).
 *
 * The commercial split is deliberate: `jobs.create` books work, `jobs.quote` puts a price on it, and
 * `jobs.close` ends it. A dispatcher holds the first two and not the third, which is what makes
 * §10.3's "only Owner may set `closed`" a gate rather than a habit.
 *
 * The price itself is not a row decision. Whether the *rate versus cost* of a job renders sits on
 * {@see FieldGate::SeeVehicleCost}'s sibling, `seeJobRateCost`, so the same row shows a dispatcher a
 * quote and an Owner the margin question behind it.
 */
final class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('jobs.view');
    }

    public function view(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && $user->can('jobs.view');
    }

    public function create(User $user): bool
    {
        return $user->can('jobs.create');
    }

    /** A dispatcher edits the jobs they booked (§9.3's "C = create + update own"); never another's closed job. */
    public function update(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && $user->can('jobs.create')
            && ! $job->status->isTerminal();
    }

    public function quote(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && $user->can('jobs.quote')
            && ! $job->status->isTerminal();
    }

    /** Acceptance is the same commercial actor as quoting, one step later (§10.3). */
    public function accept(User $user, Job $job): bool
    {
        return $this->quote($user, $job);
    }

    public function decline(User $user, Job $job): bool
    {
        return $this->quote($user, $job);
    }

    /** §10.3: only the Owner closes a job, and closing is final. */
    public function close(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && $user->can('jobs.close');
    }

    /** Cancelling pre-acceptance is the booker's cleanup, not a separate capability (§10.3). */
    public function cancel(User $user, Job $job): bool
    {
        return $this->update($user, $job);
    }

    public function suspend(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && $user->can('trips.dispatch');
    }

    /** A job is never deleted; the machine's terminal states are the record (§10.1). */
    public function delete(User $user, Job $job): bool
    {
        return false;
    }

    /** §9.3's hidden column: rate versus cost, asked at the field layer, not by this row's owner. */
    public function showRateCost(User $user): bool
    {
        return Gate::forUser($user)->allows(FieldGate::SeeTripMargin->value)
            || $user->can('jobs.rate_cost.view');
    }
}
