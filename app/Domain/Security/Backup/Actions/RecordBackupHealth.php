<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup\Actions;

use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Backup\Models\BackupHealth;
use Illuminate\Support\Carbon;

/**
 * Writes the health row: one per company per job, every run, success or failure.
 *
 * An Action rather than a few `updateOrCreate` calls in the command, because D1 keeps scheduled
 * commands from writing the database directly — and because the company loop has one rule that is easy
 * to get wrong: the row must be stamped with the tenant it describes, so each write pins that company
 * and then puts the caller's pin back.
 *
 * **A failed run never erases the last good copy's identity.** `last_success_at` is what makes "the
 * export has not worked for 40 days" visible, and the object key and digest are what let an operator
 * find the copy that *did* work. A failure therefore updates only `ok`, `last_run_at` and `detail`;
 * treating the row as a blank slate after an error is how a month of failures reads as a fresh backup.
 */
final class RecordBackupHealth
{
    /**
     * @param  list<int>  $companyIds
     * @param  array{object_key?: string|null, bytes?: int|null, rows?: int|null, tables?: int|null, sha256?: string|null}  $attributes
     */
    public function succeeded(string $job, array $companyIds, array $attributes, ?string $detail = null): int
    {
        return $this->write($job, $companyIds, true, $attributes, $detail);
    }

    /** The failure concerns the deployment, so every tenant's row says so. */
    public function failed(string $job, string $detail): int
    {
        /** @var list<int> $companyIds */
        $companyIds = Company::query()->orderBy('id')->pluck('id')->all();

        return $this->write($job, $companyIds, false, [], $detail);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $attributes
     */
    private function write(string $job, array $companyIds, bool $ok, array $attributes, ?string $detail): int
    {
        $previous = CurrentCompany::pin();
        $written = 0;

        try {
            foreach ($companyIds as $companyId) {
                CurrentCompany::force($companyId);

                /** @var BackupHealth $row */
                $row = BackupHealth::query()->firstOrNew(['job' => $job]);

                if ($ok) {
                    $row->fill(array_intersect_key($attributes, array_flip([
                        'object_key', 'bytes', 'rows', 'tables', 'sha256',
                    ])));
                    $row->last_success_at = Carbon::now();
                }

                $row->ok = $ok;
                $row->last_run_at = Carbon::now();
                $row->detail = $detail === null ? null : mb_substr($detail, 0, 500);
                $row->save();

                $written++;
            }
        } finally {
            CurrentCompany::force($previous);
        }

        return $written;
    }
}
