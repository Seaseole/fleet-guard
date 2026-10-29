<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The four badges §10.3 refuses to store.
 *
 * `all_trips_completed`, `ready_to_invoice`, `invoiced` and `settled` are questions about a job's
 * *children*, so they are answered by asking the children. Storing any of them would create the
 * failure §4.1 names directly — a trip added after the job was marked `invoiced` would sit inside a
 * job whose badge says the money is done, and every ageing report downstream would believe the badge.
 *
 * Only two of the four are answerable in Phase 2: invoices arrive in Phase 4, so `invoiced` and
 * `settled` are the Invoicing module's question to answer, not ours to guess.
 */
final class JobProgress
{
    /**
     * @param  Collection<int, Trip>  $trips
     */
    public function __construct(
        private readonly Job $job,
        private readonly Collection $trips,
    ) {}

    public static function for(Job $job): self
    {
        return new self($job, $job->trips()->get());
    }

    public function tripCount(): int
    {
        return $this->trips->count();
    }

    /** Trips still needing attention: everything except a finished, cancelled or aborted leg. */
    public function openTripCount(): int
    {
        return $this->trips
            ->reject(fn (Trip $trip): bool => in_array($trip->status, [
                TripStatus::Completed,
                TripStatus::Closed,
                TripStatus::Cancelled,
                TripStatus::Aborted,
            ], true))
            ->count();
    }

    /** §10.3's `all_trips_completed`: at least one trip, and none of them still on the road. */
    public function allTripsCompleted(): bool
    {
        return $this->tripCount() > 0 && $this->openTripCount() === 0;
    }

    /**
     * The job's badge list, as the board renders it.
     *
     * `ready_to_invoice`, `invoiced` and `settled` are absent rather than false: a Phase 2 screen
     * printing "not invoiced" would claim knowledge it does not have, since nothing that can invoice
     * a job exists yet (§15.2 Phase 4).
     *
     * @return list<array{key: string, label: string, tone: string}>
     */
    public function badges(): array
    {
        $badges = [];

        if ($this->tripCount() > 0) {
            $badges[] = [
                'key' => 'trips',
                'label' => sprintf('%d trip%s', $this->tripCount(), $this->tripCount() === 1 ? '' : 's'),
                'tone' => 'neutral',
            ];
        }

        if ($this->allTripsCompleted()) {
            $badges[] = ['key' => 'all_trips_completed', 'label' => 'All trips completed', 'tone' => 'success'];
        }

        if ($this->job->status === JobStatus::Closed) {
            $badges[] = ['key' => 'closed', 'label' => 'Closed', 'tone' => 'neutral'];
        }

        return $badges;
    }

    /** §10.3: `closed` is the Owner's decision, and the only hand-set end to a job. */
    public function mayClose(): bool
    {
        return Gate::allows('close', $this->job);
    }
}
