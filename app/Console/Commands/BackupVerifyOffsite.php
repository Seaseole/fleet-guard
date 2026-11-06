<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Security\Backup\Actions\RecordBackupHealth;
use App\Domain\Security\Backup\BackupRestorer;
use App\Domain\Security\Backup\Models\BackupHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The nightly proof that the copy off-site is still the copy we wrote: decrypt it, unpack it,
 * check every table against the manifest, and alarm if the newest copy is older than the grace period.
 *
 * **Why this is daily when the export is monthly.** The export runs on the first; a verify that also
 * ran monthly would not notice a scheduler that stopped on the second until a quarter had passed. The
 * sweep is cheap — it reads the existing object and writes nothing but the health row — so it runs
 * every night against the newest copy it finds, however old that copy is. Age is a finding, not a
 * reason to skip the read.
 *
 * `--drill` is the quarterly obligation: the same read, then a rebuild into a scratch schema and a
 * comparison of row counts and the journal's trial balance. It needs `CREATE`/`DROP` on the database
 * server, which is why it is opt-in rather than nightly.
 */
class BackupVerifyOffsite extends Command
{
    protected $signature = 'backup:verify-offsite
        {--key= : verify a named object instead of the newest copy}
        {--drill : rebuild the copy into a scratch schema and compare row counts and the trial balance}
        {--limit= : stop after this many findings}';

    protected $description = 'Decrypt the off-site export, prove every table against its manifest, and optionally drill a restore';

    public function handle(
        BackupRestorer $restorer,
        RecordBackupHealth $health,
        SelfCheckAlerter $alerter,
    ): int {
        $key = $this->option('key') === null ? null : (string) $this->option('key');
        $drill = (bool) $this->option('drill');

        try {
            $report = $drill ? $restorer->drill($key) : $restorer->inspect($key);
        } catch (Throwable $exception) {
            report($exception);

            $health->failed(BackupHealth::JOB_VERIFY, $exception->getMessage());
            $this->error('The off-site copy could not be verified: '.$exception->getMessage());

            $this->raise($alerter, 'The off-site backup could not be verified', $exception->getMessage());

            return self::FAILURE;
        }

        $findings = $report['findings'];

        // An explicitly named object may be old on purpose (an investigation, a restore rehearsal), so
        // the age alarm only applies to the sweep that is supposed to be looking at the newest copy.
        if ($key === null) {
            $staleAfter = (int) config('fleet.backup.stale_after_days');
            $days = (int) Carbon::parse($report['stored_at'])->startOfDay()->diffInDays(Carbon::now()->startOfDay());

            if ($days > $staleAfter) {
                $findings[] = [
                    'kind' => 'stale',
                    'detail' => sprintf('the newest copy is %d days old; Alarms after %d', $days, $staleAfter),
                ];
            }
        }

        $this->line(sprintf(
            ' %-12s %s',
            'copy',
            $report['object_key'],
        ));
        $this->line(sprintf(
            ' %-12s written %s, stored %s — %d bytes, %d tables, %d rows',
            '',
            $report['created_at'],
            $report['stored_at'],
            $report['bytes'],
            $report['tables'],
            $report['rows'],
        ));

        if ($drill && isset($report['schema'], $report['restored'])) {
            /** @var array{tables: int, rows: int} $restored */
            $restored = $report['restored'];

            $this->line(sprintf(
                ' %-12s %d tables / %d rows rebuilt into %s (%s), then dropped',
                'drill',
                $restored['tables'],
                $restored['rows'],
                (string) $report['schema'],
                ($report['mode'] ?? 'schema') === 'prefix'
                    ? 'table-prefix scratch — this user may not create schemas'
                    : 'scratch schema',
            ));
        }

        if ($findings === []) {
            $resolved = $alerter->clear(
                'backup:verify-offsite',
                'The next scheduled backup:verify-offsite decrypted the newest copy and every table matched its manifest.',
            );

            $health->succeeded(BackupHealth::JOB_VERIFY, $this->allCompanies(), $this->attributes($report), sprintf(
                'Verified %s: %d tables, %d rows%s.',
                $report['object_key'],
                $report['tables'],
                $report['rows'],
                $drill ? ', restored into a scratch schema' : '',
            ));

            $this->info('The copy reads back clean.'.($resolved > 0
                ? sprintf(' %d earlier alert%s resolved.', $resolved, $resolved === 1 ? '' : 's')
                : ''));

            return self::SUCCESS;
        }

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $shown = $limit === null ? $findings : array_slice($findings, 0, $limit);

        foreach ($shown as $finding) {
            $this->line(sprintf(
                ' %-16s%s — %s',
                $finding['kind'],
                isset($finding['company']) ? ' company '.$finding['company'] : '',
                $finding['detail'],
            ));
        }

        if (count($shown) < count($findings)) {
            $this->line(' … '.(count($findings) - count($shown)).' further finding(s) suppressed by --limit');
        }

        $health->failed(BackupHealth::JOB_VERIFY, sprintf(
            '%d finding(s) against %s: %s',
            count($findings),
            $report['object_key'],
            $shown[0]['detail'],
        ));

        $alertFindings = $this->attributable($findings);

        if ($alertFindings !== []) {
            $alerter->reportFindings(
                'backup:verify-offsite',
                sprintf('%d finding(s) — the off-site copy and its manifest disagree', count($findings)),
                $alertFindings,
            );
        }

        $this->newLine();
        $this->error(sprintf('%d problem(s)', count($findings)));

        return self::FAILURE;
    }

    /**
     * A table-level finding belongs to every tenant, because the copy is one archive; a trial-balance
     * finding names the company whose books did not add up.
     *
     * @param  list<array{kind: string, detail: string, company?: int}>  $findings
     * @return list<array{company: int, kind: string, detail: string}>
     */
    private function attributable(array $findings): array
    {
        $companyIds = $this->allCompanies();
        $rows = [];

        foreach ($findings as $finding) {
            if (isset($finding['company'])) {
                $rows[] = ['company' => (int) $finding['company'], 'kind' => $finding['kind'], 'detail' => $finding['detail']];

                continue;
            }

            foreach ($companyIds as $companyId) {
                $rows[] = ['company' => $companyId, 'kind' => $finding['kind'], 'detail' => $finding['detail']];
            }
        }

        return $rows;
    }

    /** @return list<int> */
    private function allCompanies(): array
    {
        /** @var list<int> $ids */
        $ids = Company::query()->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{object_key: string, bytes: int, rows: int, tables: int, sha256: string}
     */
    private function attributes(array $report): array
    {
        return [
            'object_key' => (string) $report['object_key'],
            'bytes' => (int) $report['bytes'],
            'rows' => (int) $report['rows'],
            'tables' => (int) $report['tables'],
            'sha256' => (string) $report['sha256'],
        ];
    }

    private function raise(SelfCheckAlerter $alerter, string $title, string $detail): void
    {
        $findings = array_map(fn (int $id): array => [
            'company' => $id,
            'kind' => 'verify',
            'detail' => $detail,
        ], $this->allCompanies());

        if ($findings !== []) {
            $alerter->reportFindings('backup:verify-offsite', $title, $findings);
        }
    }
}
