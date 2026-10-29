<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Security\QueueHealth;
use Illuminate\Console\Command;

final class CheckQueueHealth extends Command
{
    protected $signature = 'queues:check';

    protected $description = 'Report unavailable, failed or overdue deployment queues to the Owner';

    public function handle(QueueHealth $health, SelfCheckAlerter $alerter): int
    {
        $findings = [];
        foreach ($health->summary() as $queue) {
            if ($queue['unavailable']) {
                $findings[] = $queue['name'].': connection unavailable';
            }
            if ($queue['failed'] > 0) {
                $findings[] = $queue['name'].': '.$queue['failed'].' failed jobs';
            }
            if (($queue['age'] ?? 0) > config('fleet.queue_health.max_wait_seconds')) {
                $findings[] = $queue['name'].': oldest ready job waiting '.$queue['age'].' seconds';
            }
        }
        $companyId = (int) config('fleet.queue_health.company_id');
        if ($findings !== []) {
            $alerter->record('queues:check', 'Background work needs attention', [$companyId => $findings]);
            $this->error(implode('; ', $findings));

            return self::FAILURE;
        }
        $alerter->clear('queues:check', 'Monitored queues are reachable with no failed or overdue ready jobs.', $companyId);
        $this->info('Queue health OK.');

        return self::SUCCESS;
    }
}
