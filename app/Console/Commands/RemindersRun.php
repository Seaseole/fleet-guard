<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Data\ReminderRunReport;
use App\Domain\Kernel\Alerts\Support\ReminderRunner;
use Illuminate\Console\Command;

/**
 * §14.1's nightly scan: the only driver the reminder path is allowed to have.
 *
 * "Scheduler is the only driver; nothing in the reminder path runs on a page load" — the second half of
 * that sentence is why a register can be rendered by a query instead of by a computation, and why a page
 * view cannot accidentally email the Dispatcher.
 *
 * It exits 1 when a run raised something and told nobody. That is the same decision slice 7 made for
 * `documents:verify-integrity` and the design makes for `ledger:verify` — *silence is loud* — applied to
 * the delivery side: a threshold row naming a role that has no holder produces an accurate register and
 * an Owner who believes the licence reminder is working. The alert exists; nobody receives it. Only a
 * non-zero exit says so.
 */
class RemindersRun extends Command
{
    protected $signature = 'reminders:run
        {--company= : run one company only, for a first run or a repair}';

    protected $description = 'Scan every reminder source, raise what is new and tell the roles the thresholds name';

    public function handle(ReminderRunner $runner): int
    {
        /** @var string|null $companyOption */
        $companyOption = $this->option('company');

        $report = $companyOption === null
            ? $runner->run()
            : $runner->runOne((int) $companyOption);

        $this->line($this->summarise($report));

        if ($report->unalerted !== []) {
            $this->newLine();

            foreach ($report->unalerted as $finding) {
                $this->error('nobody was told: '.$finding);
            }

            $this->line('No alert was lost — each one above is on the register. The threshold row that '
                .'should have delivered it names a role nobody holds in that company (§14.1).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function summarise(ReminderRunReport $report): string
    {
        $line = $report->summary();

        return $report->raised === 0 && $report->escalated === 0
            ? 'Reminders run: '.$line.'. Nothing new.'
            : 'Reminders run: '.$line.'.';
    }
}
