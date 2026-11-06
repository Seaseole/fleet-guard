<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Numbering\Finding;
use App\Domain\Kernel\Numbering\NumberIntegrityChecker;
use Illuminate\Console\Command;

/**
 * Nightly proof that the sequences and the documents still agree.
 *
 * Exit code 1 on drift, so a scheduler entry or a deploy hook can alarm rather than log.
 */
class DocumentsNumberIntegrity extends Command
{
    protected $signature = 'documents:number-integrity
        {--company= : Only this company. Omit to check every company in the database.}
        {--quiet-ok : Print only problems}';

    protected $description = 'Detect gaps, duplicates and burned numbers in document sequences';

    public function handle(NumberIntegrityChecker $checker, SelfCheckAlerter $alerter): int
    {
        $company = $this->option('company');

        $findings = $checker->check($company === null ? null : (int) $company);

        if ($findings === []) {
            $alerter->clear(
                'documents:number-integrity',
                'No number_sequences rows remain, so there is no sequence left to disagree with its documents.',
                $company === null ? null : (int) $company,
            );

            $this->warn('No number_sequences rows exist yet — nothing has been numbered.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            if ($finding->status === Finding::Ok && $this->option('quiet-ok')) {
                continue;
            }

            $marker = match ($finding->status) {
                Finding::Ok => '  ok  ',
                Finding::Drift => ' DRIFT ',
                default => ' UNCHECKED ',
            };

            $this->line(sprintf(
                '%s company=%d %s%s — %s',
                $marker,
                $finding->companyId,
                $finding->scope,
                $finding->periodKey === '*' ? '' : " [{$finding->periodKey}]",
                $finding->detail,
            ));
        }

        $drift = count(array_filter($findings, fn (Finding $f) => $f->isDrift()));
        $unchecked = count(array_filter($findings, fn (Finding $f) => $f->isUnchecked()));

        // A scheduled check that finds something raises an alert, and a later clean run closes it.
        // Only drift raises one: Alarms on drift, and `unchecked` is its honest "we did not look at
        // this scope yet" — a module that has not shipped rather than a problem with one, and paging the
        // Owner for the invoicing table existing later would train everyone to ignore the register.
        $resolved = 0;

        if ($drift === 0) {
            $resolved = $alerter->clear(
                'documents:number-integrity',
                'The next scheduled documents:number-integrity run found every sequence matching its documents.',
                $company === null ? null : (int) $company,
            );
        } else {
            $report = $alerter->reportFindings(
                'documents:number-integrity',
                sprintf('%d numbering sequence(s) no longer match the documents behind them', $drift),
                array_values(array_map(
                    static fn (Finding $f): array => [
                        'company' => $f->companyId,
                        'scope' => $f->scope.($f->periodKey === '*' ? '' : " [{$f->periodKey}]"),
                        'detail' => $f->detail,
                    ],
                    array_filter($findings, static fn (Finding $f): bool => $f->isDrift()),
                )),
            );

            foreach ($report['unattributable'] as $line) {
                $this->error('finding with no company, so no alert was raised: '.$line);
            }
        }

        $this->newLine();
        $this->line(sprintf('%d checked, %d drifting, %d unchecked', count($findings), $drift, $unchecked));

        if ($drift === 0 && $resolved > 0) {
            $this->info(sprintf('%d earlier alert%s resolved by this clean run.',
                $resolved,
                $resolved === 1 ? '' : 's'));
        }

        if ($unchecked > 0) {
            $this->warn('Unchecked scopes are not failures, but they are not verified either. '
                .'Register them in config/fleet.php under numbering.stores when their module ships.');
        }

        return $drift > 0 ? self::FAILURE : self::SUCCESS;
    }
}
