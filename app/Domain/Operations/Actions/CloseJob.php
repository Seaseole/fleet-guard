<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use App\Domain\Operations\Support\JobProgress;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ends a job for good (§10.3's `→ closed`, the only state the Owner may set).
 *
 * Closing refuses while work is still on the road. §10.3 lists `all_trips_completed` before the
 * invoice states, and the reason is a bug report, not a style preference: a closed job with an open
 * trip drops that trip out of every board that filters on job status, and the truck's costs go on
 * arriving against something nobody is watching.
 */
final class CloseJob
{
    public function __invoke(Job $job, string $reason, ?int $actorId = null): Job
    {
        return DB::transaction(function () use ($job, $reason, $actorId): Job {
            $progress = JobProgress::for($job);

            if ($progress->openTripCount() > 0) {
                throw new InvalidArgumentException(sprintf(
                    'That job still has %d trip%s on the road. Complete or cancel them first (§10.3).',
                    $progress->openTripCount(),
                    $progress->openTripCount() === 1 ? '' : 's',
                ));
            }

            $job->moveTo(JobStatus::Closed, $reason, [], ActorKind::User, $actorId);
            $job->save();

            return $job->refresh();
        });
    }
}
