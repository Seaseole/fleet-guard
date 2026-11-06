<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Documents\Support\DocumentIntegrityVerifier;
use Illuminate\Console\Command;

/**
 * Nightly proof that the bytes on the private disk are still the bytes the index rows describe.
 *
 * Exit code 1 on drift, so the scheduler alarms rather than logs — the same shape as `ledger:verify`
 * (the "silence is loud" rule for the production self-checks).
 *
 * This is the layer that gives `document_versions.sha256` its meaning. The column is written from the
 * bytes at upload and nothing re-reads them afterwards, so without this command the digest is a record
 * of what was once true rather than a check on what is true now: a bucket object replaced, truncated
 * mid-sync, or deleted by hand leaves an index row that still looks healthy and a download that still
 * serves whatever is there.
 */
class DocumentsVerifyIntegrity extends Command
{
    protected $signature = 'documents:verify-integrity
        {--limit= : stop after this many findings, for a first run against a large archive}';

    protected $description = 'Compare every stored document against the digest and size its version row records';

    public function handle(DocumentIntegrityVerifier $verifier, SelfCheckAlerter $alerter): int
    {
        $findings = $verifier->findings();
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($findings === []) {
            $resolved = $alerter->clear(
                'documents:verify-integrity',
                'The next scheduled documents:verify-integrity sweep re-hashed every stored object and matched its row.',
            );

            $this->info(sprintf(
                'Documents verified: %d version%s across %d document%s, every digest matching its bytes%s',
                $verifier->versionCount(),
                $verifier->versionCount() === 1 ? '' : 's',
                $verifier->documentCount(),
                $verifier->documentCount() === 1 ? '' : 's',
                $resolved > 0
                    ? sprintf(' — %d earlier alert%s resolved.', $resolved, $resolved === 1 ? '' : 's')
                    : '.',
            ));

            return self::SUCCESS;
        }

        $shown = $limit === null ? $findings : array_slice($findings, 0, $limit);

        foreach ($shown as $finding) {
            $this->line(sprintf(
                ' %-20s document %s%s — %s',
                $finding['kind'],
                $finding['document_id'],
                $finding['version_id'] === null ? '' : ' version '.$finding['version_id'],
                $finding['detail'],
            ));
        }

        if (count($shown) < count($findings)) {
            $this->line(' … '.(count($findings) - count($shown)).' further finding(s) suppressed by --limit');
        }

        $this->newLine();
        $this->error(sprintf('%d problem(s)', count($findings)));

        $report = $alerter->reportFindings(
            'documents:verify-integrity',
            // Worded for every finding kind the sweep can return, not only the digest mismatch it was
            // first written for: a register whose title says "1 stored object no longer matches" when the
            // truth is "a current document has no current copy" sends the operator to the wrong shelf.
            sprintf('%d integrity finding%s — the register and the private disk disagree',
                count($findings),
                count($findings) === 1 ? '' : 's'),
            $findings,
        );

        foreach ($report['unattributable'] as $line) {
            $this->error('finding with no company, so no alert was raised: '.$line);
        }
        $this->line('A version row is immutable: a mismatch is investigated against the backup and the '
            .'storage layer, never corrected by editing the row to match the file — that is how a replaced document '
            .'becomes the document we always had.');

        return self::FAILURE;
    }
}
