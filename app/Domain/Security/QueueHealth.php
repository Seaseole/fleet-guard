<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class QueueHealth
{
    public function failures(): Builder
    {
        return DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->where('connection', config('queue.default'))
            ->whereIn('queue', config('fleet.queue_health.queues'));
    }

    /** @return list<array{name: string, pending: ?int, delayed: ?int, reserved: ?int, age: ?int, failed: int, unavailable: bool}> */
    public function summary(): array
    {
        $rows = [];
        $failures = $this->failures()->selectRaw('queue, count(*) as total')->groupBy('queue')->pluck('total', 'queue');

        foreach (config('fleet.queue_health.queues') as $name) {
            $row = ['name' => $name, 'pending' => null, 'delayed' => null, 'reserved' => null,
                'age' => null, 'failed' => (int) ($failures[$name] ?? 0), 'unavailable' => false];
            try {
                $queue = Queue::connection();
                $oldest = $queue->creationTimeOfOldestPendingJob($name);
                $row['pending'] = $queue->pendingSize($name);
                $row['delayed'] = $queue->delayedSize($name);
                $row['reserved'] = $queue->reservedSize($name);
                $row['age'] = $oldest === null ? null : max(0, time() - $oldest);
            } catch (Throwable $exception) {
                report($exception);
                $row['unavailable'] = true;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
