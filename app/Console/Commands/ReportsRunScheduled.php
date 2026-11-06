<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Models\ScheduledReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The calendar's half: every company, every enabled nomination, and only the rows whose
 * frequency matches the day. The command decides *due*; the job does the reading, writing and
 * mailing — so one stuck report cannot hold the whole schedule up, and the queue-health screen
 * already watches the failure mode.
 *
 * `--date` is the harness door: "is this due today" is a decision worth testing against a
 * Sunday and a 31st-less month without waiting for either.
 */
final class ReportsRunScheduled extends Command
{
    protected $signature = 'reports:run-scheduled
                            {--company= : Only this company (default: every company)}
                            {--date= : Evaluate "today" as this Y-m-d date instead}';

    protected $description = 'Send the report mails the companies nominated';

    public function handle(): int
    {
        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : DB::table('companies')->orderBy('id')->pluck('id')->all();

        $dispatched = 0;
        $skipped = 0;

        foreach ($companyIds as $companyId) {
            CurrentCompany::force((int) $companyId);

            try {
                $today = $this->option('date') !== null
                    ? Carbon::parse((string) $this->option('date'))->startOfDay()
                    : Carbon::now(CurrentCompany::timezone())->startOfDay();

                foreach (ScheduledReport::query()->where('enabled', true)->get() as $scheduled) {
                    if (! $scheduled->isDueOn($today)) {
                        $skipped++;

                        continue;
                    }

                    SendScheduledReport::dispatch($scheduled->id, (int) $companyId)->onQueue('reports');
                    $dispatched++;
                }
            } finally {
                CurrentCompany::forget();
            }
        }

        $this->info("dispatched {$dispatched} · not due {$skipped} · across ".count($companyIds).' company(ies)');

        return self::SUCCESS;
    }
}
