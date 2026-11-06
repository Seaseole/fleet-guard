<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Models\Job;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The customer said yes (the `quoted → accepted`).
 *
 * Acceptance is what makes a job executable: The rule is that trips hang off an accepted job, and
 * {@see CreateTrip} refuses anything else. Nothing is copied or derived at this moment — the quote
 * stands as the offer that was accepted, and the invoice Phase 4 raises carries its own rate block
 * rather than inheriting this one.
 *
 * Authorisation is the screen's job (D1); this Action owns the one invariant the state machine cannot
 * see: accepting a job with no price on it would put an unpriced promise into execution.
 */
final class AcceptJob
{
    public function __invoke(Job $job, ?int $actorId = null): Job
    {
        return DB::transaction(function () use ($job, $actorId): Job {
            if ($job->currency_code === null || $job->quoted_amount <= 0) {
                throw new InvalidArgumentException('There is nothing to accept yet — the job has no price on it.');
            }

            $job->moveTo(JobStatus::Accepted, null, [], ActorKind::User, $actorId);
            $job->save();

            return $job->refresh();
        });
    }
}
