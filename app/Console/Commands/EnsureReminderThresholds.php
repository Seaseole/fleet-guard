<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Support\ThresholdRepository;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Give every company the thresholds its sources declare — once, and never again (§14.1).
 *
 * §14.1 made thresholds rows so the Owner could change them, and a rule that lives only in data has the
 * hole that *nothing* is also data: a company created last week would simply have no reminders, and the
 * register would be right about that while the Owner read it as "nothing is expiring". This command is
 * what turns an absent family into a decision somebody made.
 *
 * It is additive on purpose. A row the Owner edited is left alone, and a family they deleted is not put
 * back — re-creating what a deliberate act removed would turn a maintenance command into a second Author.
 */
class EnsureReminderThresholds extends Command
{
    protected $signature = 'reminders:ensure-thresholds';

    protected $description = 'Create the default reminder thresholds for any company that has none';

    public function handle(ThresholdRepository $thresholds): int
    {
        /** @var array<ReminderSource> $sources */
        $sources = iterator_to_array($this->laravel->tagged('reminder_sources'));

        if ($sources === []) {
            $this->error('No reminder source is registered. A schedule that scans nothing reports a clean '
                .'run, which is the least useful thing a command can do (§14.6).');

            return self::FAILURE;
        }

        $previous = CurrentCompany::pin();

        try {
            /** @var list<int> $companyIds */
            $companyIds = Company::query()->orderBy('id')->pluck('id')->all();

            $created = 0;

            foreach ($companyIds as $companyId) {
                CurrentCompany::force($companyId);

                $rows = $thresholds->ensureDefaults($sources);
                $created += count($rows);

                $this->line(sprintf(
                    ' company %d: %d threshold row%s%s',
                    $companyId,
                    count($rows),
                    count($rows) === 1 ? '' : 's',
                    $rows === [] ? ' (already configured)' : '',
                ));
            }

            $this->newLine();
            $this->info($created === 0
                ? 'Every company already has its thresholds; nothing was written.'
                : sprintf('%d default threshold%s established across %d compan%s.',
                    $created,
                    $created === 1 ? ' row was' : ' rows were',
                    count($companyIds),
                    count($companyIds) === 1 ? 'y' : 'ies',
                ));
        } finally {
            CurrentCompany::force($previous);
        }

        return self::SUCCESS;
    }
}
