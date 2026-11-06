<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Security\Backup\Actions\RecordBackupHealth;
use App\Domain\Security\Backup\BackupExporter;
use App\Domain\Security\Backup\Models\BackupHealth;
use Illuminate\Console\Command;
use Throwable;

/**
 * The monthly application-level export, scheduled on the first of the month.
 *
 * The command is thin because the work belongs to {@see BackupExporter}: it runs the export, records
 * the health row every tenant's dashboard reads, and raises a self-check alert if the copy did not
 * leave the building. A failed export that only exits 1 is the failure exists to end — nobody
 * watches a scheduler's stdout at 02:00, and the runbook depends on the register saying a copy is
 * overdue rather than on someone noticing a quiet log.
 */
class BackupExportOffsite extends Command
{
    protected $signature = 'backup:export-offsite';

    protected $description = 'Write the ledger-critical tables off-site as an encrypted, compressed CSV archive';

    public function handle(
        BackupExporter $exporter,
        RecordBackupHealth $health,
        SelfCheckAlerter $alerter,
    ): int {
        try {
            $result = $exporter->export();
        } catch (Throwable $exception) {
            report($exception);

            $health->failed(BackupHealth::JOB_EXPORT, $exception->getMessage());
            $this->error('The off-site export did not complete: '.$exception->getMessage());

            $this->raise($alerter, 'The off-site backup did not complete', $exception->getMessage());

            return self::FAILURE;
        }

        $health->succeeded(
            BackupHealth::JOB_EXPORT,
            $result['company_ids'],
            [
                'object_key' => $result['object_key'],
                'bytes' => $result['bytes'],
                'rows' => $result['rows'],
                'tables' => $result['tables'],
                'sha256' => $result['sha256'],
            ],
            sprintf('%d tables, %d rows, %d bytes.', $result['tables'], $result['rows'], $result['bytes']),
        );

        $resolved = $alerter->clear(
            'backup:export-offsite',
            'The next scheduled backup:export-offsite wrote a new off-site copy.',
        );

        $this->info(sprintf(
            'Off-site export written: %s — %d tables, %d rows, %d bytes%s',
            $result['object_key'],
            $result['tables'],
            $result['rows'],
            $result['bytes'],
            $resolved > 0 ? sprintf('; %d earlier alert%s resolved.', $resolved, $resolved === 1 ? '' : 's') : '.',
        ));

        return self::SUCCESS;
    }

    private function raise(SelfCheckAlerter $alerter, string $title, string $detail): void
    {
        /** @var list<int> $companyIds */
        $companyIds = Company::query()->orderBy('id')->pluck('id')->all();

        $findings = array_map(static fn (int $id): array => [
            'company' => $id,
            'kind' => 'export',
            'detail' => $detail,
        ], $companyIds);

        if ($findings !== []) {
            $alerter->reportFindings('backup:export-offsite', $title, $findings);
        }
    }
}
