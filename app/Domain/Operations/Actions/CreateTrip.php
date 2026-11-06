<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Models\Trip;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opens a trip as a **draft** against an accepted job (the `[*] → draft`, the job → trips).
 *
 * Nothing is chosen here except *which job* is being executed, and that is the whole point of the
 * draft state: a dispatcher booking a truck for Thursday does not always know the driver yet, and a
 * half-filled plan is a normal fact of the job. The states after draft are where the promises start —
 * `planned` names a date, `assigned` names a crew, and both can be refused. `trips_plan_is_named`
 * keeps the two honest at the engine, so a draft is the only row that may have no truck on it.
 *
 * The route is copied from the job rather than read through it later (corridor reporting groups
 * on the route the trip actually ran, and a master-data edit to the job must not rewrite history).
 */
final class CreateTrip
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function __invoke(Job $job, ?int $actorId = null): Trip
    {
        return DB::transaction(function () use ($job, $actorId): Trip {
            if (! $job->status->mayRunTrips()) {
                throw new InvalidArgumentException(sprintf(
                    'A trip cannot run against %s: the job is %s. Only an accepted job may be executed.',
                    $job->job_no,
                    $job->status->label(),
                ));
            }

            // The first trip is what turns an accepted job into work in motion (the
            // `accepted → in_execution`). Guarded rather than assumed because a later leg is added to
            // a job that is already running, and the machine refuses that move twice.
            if ($job->status === JobStatus::Accepted) {
                $job->moveTo(JobStatus::InExecution, null, [], ActorKind::User, $actorId);
                $job->save();
            }

            /** @var Trip $trip */
            $trip = Trip::create([
                'trip_no' => $this->numbers->allocate('trip', null, $actorId),
                'job_id' => $job->id,
                'route_id' => $job->route_id,
            ]);

            return $trip;
        });
    }
}
