<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\DataGovernance\Actions\RotateFieldKeys;
use App\Domain\Security\DataGovernance\MissingFieldKey;
use Illuminate\Console\Command;

/**
 * The key rotation, scheduled like any other job: set the new key and `DB_CRYPT_KEY_VERSION`,
 * run this, then retire the old key once the report says nothing is left on it.
 *
 * Thin on purpose (D1): the scan, the per-row decrypt/re-encrypt and the audit row are the Action's.
 * The command only turns three options into arguments and a report into an exit code, so the same
 * rotation can be run from a verifier without a console.
 *
 * `--dry-run` is the option that makes the first run against production safe: it names the versions
 * present and how many rows would move, without writing a single payload.
 */
final class KeysReencrypt extends Command
{
    protected $signature = 'keys:reencrypt
        {--dry-run : Report what is on an older key version without writing anything}
        {--batch=500 : Rows read per batch}
        {--company= : Rotate one company\'s rows only}';

    protected $description = 'Re-encrypt every column onto the current DB_CRYPT_KEY version';

    public function handle(RotateFieldKeys $rotate): int
    {
        $company = $this->option('company');
        $companyId = is_string($company) && $company !== '' ? (int) $company : null;
        $batch = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $rotate($companyId, $dryRun, $batch > 0 ? $batch : 500);
        } catch (MissingFieldKey $missing) {
            $this->error('Rotation refused before any write: '.$missing->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>keys:reencrypt</> — %s%s',
            $report['from'] === []
                ? 'everything is on '.$report['to']
                : implode(', ', $report['from']).' to '.$report['to'],
            $dryRun ? ' (dry run)' : '',
        ));

        $this->line(sprintf(
            '  %d table(s), %d row(s) holding ciphertext, %d on an older version, %d %s, %d failed',
            $report['tables'],
            $report['rows'],
            $report['pending'],
            $report['rotated'],
            $dryRun ? 'would be rotated' : 'rotated',
            $report['failed'],
        ));

        if ($companyId !== null) {
            $this->line(sprintf('  scoped to company %d', $companyId));
        }

        if ($report['findings'] !== []) {
            $this->newLine();

            foreach ($report['findings'] as $finding) {
                $this->line(sprintf(
                    '  <fg=red>FAIL</> %s#%s — %s: %s',
                    $finding['table'],
                    $finding['id'],
                    $finding['column'],
                    $finding['finding'],
                ));
            }
        }

        if ($report['failed'] > 0) {
            $this->newLine();
            $this->error(sprintf(
                '  %d row(s) still hold a version the current key cannot move. Retire nothing until they are resolved.',
                $report['failed'],
            ));

            return self::FAILURE;
        }

        if ($dryRun && $report['pending'] > 0) {
            $this->newLine();
            $this->warn('  Dry run only. Re-run without --dry-run to write the new payloads.');

            return self::SUCCESS;
        }

        if ($report['pending'] === 0) {
            $this->info('  Every encrypted column is already on the current key version.');
        }

        return self::SUCCESS;
    }
}
