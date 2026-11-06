<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use App\Domain\Kernel\Tenancy\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The monthly application-level export: the ledger-critical tables as gzipped CSV, sealed with
 * AES-256-GCM and written to a disk that is not the database's.
 *
 * **Why raw rows and not a database dump.** Cloud snapshots are not downloadable as `mysqldump`
 * files (ADR-0001, R3), so the only copy the operator can hold, move between providers and read
 * with an ordinary tool is one the application writes itself. A dump would also carry the trigger
 * and grant surface, which is not what this copy is for: it exists to restore the *books*, not to
 * rebuild the cluster.
 *
 * **What the manifest is for.** Every table's row count, digest, column list and `SHOW CREATE TABLE`
 * travel inside the payload. The verify sweep can therefore prove what it holds without a database,
 * and the drill can build a scratch schema without trusting the live schema it is trying to
 * reproduce.
 */
final class BackupExporter
{
    public function __construct(private readonly BackupCipher $cipher) {}

    /**
     * @return array{
     *     object_key: string,
     *     bytes: int,
     *     sha256: string,
     *     rows: int,
     *     tables: int,
     *     company_ids: list<int>,
     *     manifest: array<string, mixed>
     * }
     */
    public function export(): array
    {
        $configured = config('fleet.backup.tables');

        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException('fleet.backup.tables is empty; an export with nothing in it is not a backup.');
        }

        /** @var list<string> $tables */
        $tables = array_values($configured);

        $work = $this->workDirectory();
        $payload = $work.DIRECTORY_SEPARATOR.'payload.zip';
        $sealed = $work.DIRECTORY_SEPARATOR.'payload.'.BackupCipher::FILE_EXTENSION;

        try {
            $manifest = $this->manifest($tables, $work);
            $this->zip($manifest, $work, $payload);
            $sealedResult = $this->cipher->encryptFile($payload, $sealed);

            $key = $this->objectKey();
            $stored = $this->put($key, $sealed);

            return [
                'object_key' => $key,
                // The sealed object's size, not the plaintext's: `backup:verify-offsite` reports the
                // bytes it downloaded, and two rows about one object must not disagree about it.
                'bytes' => $stored,
                'sha256' => $sealedResult['sha256'],
                'rows' => (int) array_sum(array_map(static fn (array $t): int => (int) $t['rows'], $manifest['tables'])),
                'tables' => count($manifest['tables']),
                'company_ids' => $manifest['company_ids'],
                'manifest' => $manifest,
            ];
        } finally {
            $this->removeDirectory($work);
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function manifest(array $tables, string $work): array
    {
        $entries = [];

        foreach ($tables as $table) {
            $entries[$table] = $this->exportTable($table, $work);
        }

        /** @var list<int> $companyIds */
        $companyIds = Company::query()->orderBy('id')->pluck('id')->all();

        return [
            'format' => 1,
            'app' => 'fleet-guard',
            'created_at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(),
            'key_fingerprint' => $this->cipher->fingerprint(),
            'company_ids' => $companyIds,
            'tables' => $entries,
            'trial_balance' => $this->trialBalance(),
        ];
    }

    /**
     * @return array{rows: int, columns: list<string>, pk: list<string>, sha256: string, bytes: int, ddl: string}
     */
    private function exportTable(string $table, string $work): array
    {
        $columns = $this->writableColumns($table);
        $primaryKey = $this->primaryKey($table);

        $path = $work.DIRECTORY_SEPARATOR.$table.'.csv.gz';
        $handle = fopen('compress.zlib://'.$path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open the compressed CSV for {$table}.");
        }

        $rows = 0;

        try {
            fputcsv($handle, $columns, escape: '\\');

            $query = DB::table($table);

            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            // `cursor()` streams: a year of journal lines is exported without holding the table in
            // memory, and the ordering by primary key keeps two exports of unchanged data identical
            // enough that a diff means something.
            foreach ($query->cursor() as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;

                fputcsv(
                    $handle,
                    array_map(static fn (string $column): string => self::csvValue($values[$column] ?? null), $columns),
                    escape: '\\',
                );

                $rows++;
            }
        } finally {
            fclose($handle);
        }

        $digest = hash_file('sha256', $path);
        $size = filesize($path);

        if ($digest === false || $size === false) {
            throw new RuntimeException("The compressed CSV for {$table} could not be measured.");
        }

        return [
            'rows' => $rows,
            'columns' => $columns,
            'pk' => $primaryKey,
            'sha256' => $digest,
            'bytes' => $size,
            'ddl' => $this->ddl($table),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function zip(array $manifest, string $work, string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the export archive at {$path}.");
        }

        try {
            foreach ($manifest['tables'] as $table => $entry) {
                if ($zip->addFile($work.DIRECTORY_SEPARATOR.$table.'.csv.gz', 'tables/'.$table.'.csv.gz') === false) {
                    throw new RuntimeException("Could not add {$table} to the export archive.");
                }
            }

            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } finally {
            $zip->close();
        }
    }

    /** @return int the bytes the disk stored, which is the copy's own size */
    private function put(string $key, string $sealedPath): int
    {
        $disk = Storage::disk((string) config('fleet.backup.disk'));
        $handle = fopen($sealedPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open the sealed export at {$sealedPath}.");
        }

        try {
            if (! $disk->writeStream($key, $handle)) {
                throw new RuntimeException("The off-site disk refused the export at {$key}.");
            }
        } finally {
            fclose($handle);
        }

        $written = $disk->size($key);
        $local = filesize($sealedPath);

        // A bucket that accepted a stream but stored part of it is the failure a backup cannot detect
        // later: verify would read a shorter file and call the export corrupt. Catch it now, while the
        // evidence is on this machine.
        if ($local !== false && $written !== false && $written !== $local) {
            $disk->delete($key);

            throw new RuntimeException(sprintf(
                'The off-site disk stored %d bytes for %s but the sealed export is %d; the object was removed rather than filed as a complete backup.',
                $written,
                $key,
                $local,
            ));
        }

        $stored = $written !== false ? $written : $local;

        if ($stored === false) {
            $disk->delete($key);

            throw new RuntimeException("The off-site disk stored {$key} but would not report its size; the object was removed rather than filed unmeasured.");
        }

        return (int) $stored;
    }

    private function objectKey(): string
    {
        $prefix = trim((string) config('fleet.backup.prefix'), '/');

        return $prefix.'/'.now()->format('Y/m')
            .'/export-'.now()->format('Ymd-His')
            .'-'.Str::lower(Str::random(6))
            .'.'.BackupCipher::FILE_EXTENSION;
    }

    /**
     * The columns a restore may write.
     *
     * Generated columns are excluded on purpose: MySQL refuses an explicit value for one, so an export
     * that carried them would produce a copy that can be verified and never restored — the failure
     * this drill exists to find, found at the wrong time. Their values are recomputed by the restored
     * table's own definition, which travels in the DDL.
     *
     * @return list<string>
     */
    private function writableColumns(string $table): array
    {
        /** @var list<object{Field: string, Extra: string}> $rows */
        $rows = DB::select('show columns from `'.str_replace('`', '``', $table).'`');

        if ($rows === []) {
            throw new RuntimeException("fleet.backup.tables names {$table}, but the schema has no such table.");
        }

        $columns = [];

        foreach ($rows as $row) {
            if (str_contains(strtoupper((string) ($row->Extra ?? '')), 'GENERATED')) {
                continue;
            }

            $columns[] = (string) $row->Field;
        }

        return $columns;
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        // information_schema rather than `show keys`: MySQL accepts `where` on SHOW INDEX but not
        // `order by`, and the order of a composite key is what decides the export's stable ordering.
        /** @var list<object{pk_column: string}> $rows */
        $rows = DB::select(
            'select column_name as pk_column from information_schema.statistics '
            .'where table_schema = database() and table_name = ? and index_name = \'PRIMARY\' '
            .'order by seq_in_index',
            [$table],
        );

        return array_map(static fn (object $row): string => (string) $row->pk_column, $rows);
    }

    private function ddl(string $table): string
    {
        /** @var list<object{Created_table: string}> $rows */
        $rows = DB::select('show create table `'.str_replace('`', '``', $table).'`');

        if (! isset($rows[0])) {
            throw new RuntimeException("The schema would not describe {$table}.");
        }

        /** @var array<string, string> $row */
        $row = (array) $rows[0];

        return (string) ($row['Create Table'] ?? '');
    }

    /** @return array<string, array{debit: int, credit: int, lines: int}> */
    private function trialBalance(): array
    {
        /** @var array{lines: string, entries: string, amount: string, direction: string, debit: string, credit: string} $config */
        $config = config('fleet.backup.trial_balance');

        // `selectRaw()` requires a literal string, and the column names this statement names are
        // deployment data from config; `DB::select()` takes the assembled SQL and keeps the two
        // direction values as bindings, which is what actually needs quoting.
        $sql = 'select e.company_id as company, '
            .'sum(case when l.`'.$config['direction'].'` = ? then l.`'.$config['amount'].'` else 0 end) as debit, '
            .'sum(case when l.`'.$config['direction'].'` = ? then l.`'.$config['amount'].'` else 0 end) as credit, '
            .'count(*) as line_count'
            .' from '.$config['lines'].' as l'
            .' join '.$config['entries'].' as e on e.id = l.entry_id'
            .' group by e.company_id';

        /** @var list<object{company: int|string, debit: int|string, credit: int|string, line_count: int|string}> $rows */
        $rows = DB::select($sql, [$config['debit'], $config['credit']]);

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->company] = [
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                'lines' => (int) $row->line_count,
            ];
        }

        return $totals;
    }

    private function workDirectory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fg-backup-'.Str::lower(Str::random(10));

        if (! mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create the export work directory {$path}.");
        }

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            try {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            } catch (Throwable) {
                // Best effort: a temp file that refuses deletion is not worth failing a completed
                // export, and the OS will reclaim it.
            }
        }

        @rmdir($path);
    }

    private static function csvValue(mixed $value): string
    {
        // MySQL's own convention for "no value": an empty string is a value, a missing one is `\N`.
        // Without the distinction a restore turns every NULL into '' and a nullable JSON column
        // refuses the row.
        return $value === null ? '\\N' : (string) $value;
    }
}
