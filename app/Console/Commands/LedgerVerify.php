<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Ledger\Support\LedgerVerifier;
use Illuminate\Console\Command;

/**
 * Nightly proof that the ledger's header rows still describe their own lines (layer 5).
 *
 * Exit code 1 on drift so the scheduler alarms instead of logging. This is the layer that
 * replaces the DB-grant control Laravel Cloud does not offer: immutability against someone with
 * database credentials is an operational control here, and an operational control nobody runs is
 * not a control.
 */
class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify
        {--quiet-ok : Print only problems}';

    protected $description = 'Recompute journal entry totals from their lines and report any drift';

    public function handle(LedgerVerifier $verifier, SelfCheckAlerter $alerter): int
    {
        $findings = $verifier->findings();
        $count = $verifier->postedEntryCount();

        if ($findings === []) {
            // A clean run closes the finding it disproved, or the register fills with accurate alerts
            // about drift that was fixed three weeks ago.
            $resolved = $alerter->clear('ledger:verify', 'The next scheduled ledger:verify run found no drift.');

            $this->info("Ledger verified: {$count} posted entr".($count === 1 ? 'y' : 'ies').', no drift.'
                .($resolved > 0 ? " ({$resolved} earlier alert".($resolved === 1 ? '' : 's').' resolved).' : ''));

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line(sprintf(
                ' DRIFT  %s (%s) — %s',
                $finding['entry_no'],
                $finding['entry'],
                $finding['finding'],
            ));
        }

        $report = $alerter->reportFindings(
            'ledger:verify',
            sprintf('%d ledger drift finding(s) across %d posted entries', count($findings), $count),
            $findings,
        );

        foreach ($report['unattributable'] as $line) {
            $this->error('finding with no company, so no alert was raised: '.$line);
        }

        $this->newLine();
        $this->error(sprintf('%d problem(s) across %d posted entries', count($findings), $count));
        $this->line('Ledger rows are immutable by design: correct one with a reversing entry, '
            .'or use LedgerBarrier::withLiftedGuard() from a maintenance migration and leave the reason recorded.');

        return self::FAILURE;
    }
}
