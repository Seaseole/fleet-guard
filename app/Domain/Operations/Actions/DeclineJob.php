<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use Illuminate\Support\Facades\DB;

/**
 * The customer went elsewhere, or the fleet could not take the work (the `→ declined`).
 *
 * Declining is only reachable from `enquiry` or `quoted` — the design puts both exits before acceptance,
 * because a declined *accepted* job is a cancelled one, and the difference matters to anyone reading
 * the board later: one was never won, the other was won and then not run.
 *
 * The reason is mandatory at the machine itself, which is why this Action takes it as a
 * positional argument rather than an optional one.
 */
final class DeclineJob
{
    public function __invoke(Job $job, string $reason, ?int $actorId = null): Job
    {
        return DB::transaction(function () use ($job, $reason, $actorId): Job {
            $job->moveTo(JobStatus::Declined, $reason, [], ActorKind::User, $actorId);
            $job->save();

            return $job->refresh();
        });
    }
}
