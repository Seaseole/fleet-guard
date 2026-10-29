<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Security\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RetryFailedJob
{
    public function __invoke(string $uuid): void
    {
        Gate::authorize('queues.retry');
        abort_if(time() - (int) session('auth.password_confirmed_at', 0) > config('fleet.identity.reauth_window_seconds'), 403);
        Validator::make(['uuid' => $uuid], ['uuid' => 'required|uuid'])->validate();

        DB::connection(config('queue.failed.database'))->transaction(function () use ($uuid): void {
            $job = app(QueueHealth::class)->failures()->where('uuid', $uuid)->lockForUpdate()->first(['id', 'uuid', 'queue', 'connection']);
            abort_if($job === null, 404);

            Artisan::call('queue:retry', ['id' => [$job->uuid]]);

            // queue:retry reports an unfindable job by printing, and its exit code stays 0. The row
            // is the only proof that the requeue happened, and the requeue and the delete share this
            // transaction when the queue lives in the same database, so a survivor rolls both back.
            abort_if(app(QueueHealth::class)->failures()->where('uuid', $job->uuid)->exists(), 422, 'The job could not be returned to the queue.');

            app(AuditRecorder::class)->operational(
                aggregateType: 'failed_job',
                aggregateId: (string) $job->id,
                fromState: 'failed',
                toState: 'retry_requested',
                reason: 'Owner requested retry after reviewing queue health.',
                context: ['uuid' => $job->uuid, 'queue' => $job->queue, 'connection' => $job->connection],
            );
        });
    }
}
