<?php

declare(strict_types=1);

namespace App\Domain\Security\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The restore drill: take the copy that actually sits off-site, decrypt it, read every table back,
 * and rebuild the books in a scratch schema far enough to prove they still describe themselves.
 *
 * **Why the drill is in this deployment and not the Cloud console.** A Cloud snapshot cannot be opened
 * by the application (ADR-0001, R3), and a backup nobody has ever restored is a hypothesis. The
 * quarterly obligation is therefore split: the operator's Cloud restore is documented in the runbook,
 * and this class proves the *application-level* half — that the bytes we wrote months ago still
 * decrypt, still unpack, and still add up to the trial balance recorded when they were written.
 *
 * **What "restored" means here.** The scratch schema gets the table definitions recorded in the
 * manifest, with `FOREIGN KEY` clauses removed: the copy holds the ledger-critical tables only, so a
 * parent such as `customers` is present in the live schema and absent here, and keeping the keys would
 * make the drill fail for a reason that says nothing about the data. Every row is inserted, every
 * count is compared to the manifest, and the journal's debit and credit totals are recomputed from the
 * restored rows and compared to the totals written at export time. Any disagreement is a finding, not
 * an exception: a drill that stops at its first surprise tells the operator less than one that lists
 * them all.
 *
 * **The archive is not trusted just because it decrypted.** GCM proves the bytes are the bytes we
 * sealed; it does not prove the *manifest* is one we wrote. The table list is therefore intersected
 * with `fleet.backup.tables` before any DDL runs, because the drill executes `SHOW CREATE TABLE`
 * output from the archive, and "the copy told me to create this table" is not an authorization.
 */
final class BackupRestorer
{
    private const BATCH = 500;

    public function __construct(private readonly BackupCipher $cipher) {}

    /**
     * Read the copy back without touching the database: decrypt, unpack, and prove every table's
     * digest and row count against the manifest. This is what the nightly verify sweep needs.
     *
     * @return array{
     *     object_key: string,
     *     stored_at: string,
     *     bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     tables: int,
     *     rows: int,
     *     company_ids: list<int>,
     *     findings: list<array{kind: string, detail: string}>
     * }
     */
    public function inspect(?string $objectKey = null): array
    {
        $work = $this->workDirectory();

        try {
            return $this->report($this->stage($objectKey, $work));
        } finally {
            $this->removeDirectory($work);
        }
    }

    /**
     * The quarterly drill: everything `inspect()` proves, plus a scratch rebuild, a row-count
     * comparison and the trial balance re-derived from the restored rows.
     *
     * **Two ways to be scratch, because the target is a managed database.** A dedicated user on a
     * shared MySQL server usually holds `CREATE TEMPORARY TABLES` and DML on its own schema but not
     * `CREATE DATABASE` — Laravel Cloud's managed user is exactly that shape. So the drill first tries
     * a real scratch schema, and if the server refuses it, rebuilds into `fleet_guard_drill_*` tables
     * inside the application's own schema instead. Both are dropped afterwards; neither can see or
     * touch a live table, because every statement is fully qualified with a name this class minted.
     *
     * @return array{
     *     object_key: string,
     *     stored_at: string,
     *     bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     tables: int,
     *     rows: int,
     *     company_ids: list<int>,
     *     mode: string,
     *     schema: string,
     *     restored: array{tables: int, rows: int},
     *     findings: list<array{kind: string, detail: string}>
     * }
     */
    public function drill(?string $objectKey = null): array
    {
        $work = $this->workDirectory();

        try {
            $staged = $this->stage($objectKey, $work);
            $manifest = $staged['manifest'];
            $findings = $staged['findings'];
            $namespace = $this->scratchNamespace();
            $restored = ['tables' => 0, 'rows' => 0];

            try {
                $this->createNamespace($namespace);
                $this->load($namespace, $manifest, $staged['directory'], $findings);
                $restored = $this->compareCounts($namespace, $manifest, $findings);
                $this->compareTrialBalance($namespace, $manifest, $findings);
            } finally {
                if (! $this->dropNamespace($namespace)) {
                    $findings[] = [
                        'kind' => 'scratch_schema',
                        'detail' => "the drill objects under {$namespace['name']} could not all be dropped; the prefix makes them safe to remove by hand.",
                    ];
                }
            }

            /**
             * @var array{
             *     object_key: string,
             *     stored_at: string,
             *     bytes: int,
             *     created_at: string,
             *     sha256: string,
             *     tables: int,
             *     rows: int,
             *     company_ids: list<int>,
             *     findings: list<array{kind: string, detail: string}>,
             *     mode: string,
             *     schema: string,
             *     restored: array{tables: int, rows: int}
             * } $report
             */
            $report = $this->report($staged);
            $report['mode'] = $namespace['mode'];
            $report['schema'] = $namespace['name'];
            $report['restored'] = $restored;
            $report['findings'] = $findings;

            return $report;
        } finally {
            $this->removeDirectory($work);
        }
    }

    /**
     * @param  array{
     *     object_key: string,
     *     stored_at: string,
     *     bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     manifest: array<string, mixed>,
     *     directory: string,
     *     findings: list<array{kind: string, detail: string}>
     * }  $staged
     * @return array{
     *     object_key: string,
     *     stored_at: string,
     *     bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     tables: int,
     *     rows: int,
     *     company_ids: list<int>,
     *     findings: list<array{kind: string, detail: string}>
     * }
     */
    private function report(array $staged): array
    {
        $manifest = $staged['manifest'];
        $tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

        return [
            'object_key' => $staged['object_key'],
            'stored_at' => $staged['stored_at'],
            'bytes' => $staged['bytes'],
            'created_at' => $staged['created_at'],
            'sha256' => $staged['sha256'],
            'tables' => count($tables),
            'rows' => (int) array_sum(array_map(
                static fn (mixed $entry): int => is_array($entry) ? (int) ($entry['rows'] ?? 0) : 0,
                $tables,
            )),
            'company_ids' => array_values(array_map('intval', (array) ($manifest['company_ids'] ?? []))),
            'findings' => $staged['findings'],
        ];
    }

    /**
     * The latest copy on the configured off-site disk, newest by storage timestamp.
     *
     * Not filtered to the current month: a scheduler that stopped in March must be found by the April
     * sweep, and a version filter would hide the very copy whose age is the finding.
     */
    public function latestObjectKey(): string
    {
        $disk = $this->disk();
        $prefix = trim((string) config('fleet.backup.prefix'), '/');
        $latest = null;
        $latestAt = null;

        foreach ($disk->allFiles($prefix) as $file) {
            if (! str_ends_with($file, '.'.BackupCipher::FILE_EXTENSION)) {
                continue;
            }

            $at = $disk->lastModified($file);

            if ($latest === null || $at > $latestAt) {
                $latest = $file;
                $latestAt = $at;
            }
        }

        if ($latest === null) {
            throw new MissingBackupCopy(sprintf(
                'The off-site disk %s holds no *.%s copy under %s/. Nothing has been exported, or the disk is the wrong one.',
                (string) config('fleet.backup.disk'),
                BackupCipher::FILE_EXTENSION,
                $prefix,
            ));
        }

        return $latest;
    }

    /**
     * @return array{
     *     object_key: string,
     *     stored_at: string,
     *     bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     manifest: array<string, mixed>,
     *     directory: string,
     *     findings: list<array{kind: string, detail: string}>
     * }
     */
    private function stage(?string $objectKey, string $work): array
    {
        $key = $objectKey ?? $this->latestObjectKey();
        $disk = $this->disk();
        $storedAt = $disk->lastModified($key);

        $sealed = $work.DIRECTORY_SEPARATOR.'payload.'.BackupCipher::FILE_EXTENSION;
        $bytes = $this->download($key, $sealed);

        try {
            $plain = $work.DIRECTORY_SEPARATOR.'payload.zip';
            $footer = $this->cipher->decryptFile($sealed, $plain);
            @unlink($sealed);

            $directory = $work.DIRECTORY_SEPARATOR.'extracted';
            $manifest = $this->extract($plain, $directory);
            @unlink($plain);
        } finally {
            @unlink($sealed);
        }

        $findings = [];
        $this->checkManifest($manifest, $findings);
        $this->checkPayload($manifest, $directory, $findings);

        return [
            'object_key' => $key,
            'stored_at' => date(DATE_ATOM, $storedAt),
            'bytes' => $bytes,
            'created_at' => $footer['created_at'] !== '' ? $footer['created_at'] : (string) ($manifest['created_at'] ?? ''),
            'sha256' => $footer['sha256'],
            'manifest' => $manifest,
            'directory' => $directory,
            'findings' => $findings,
        ];
    }

    private function download(string $key, string $path): int
    {
        $stream = $this->disk()->readStream($key);

        if ($stream === null) {
            throw new MissingBackupCopy("The off-site disk holds no readable object at {$key}.");
        }

        $out = fopen($path, 'wb');

        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException("Could not open {$path} for the downloaded copy.");
        }

        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new RuntimeException("Could not download {$key} from the off-site disk.");
            }
        } finally {
            fclose($out);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $bytes = filesize($path);

        if ($bytes === false || $bytes === 0) {
            throw BackupCorrupt::unreadable('the sealed copy arrived empty');
        }

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function extract(string $archive, string $directory): array
    {
        if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory} to unpack the copy into.");
        }

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw BackupCorrupt::unreadable('the inner archive could not be opened, so the payload is not a zip');
        }

        try {
            if (! $zip->extractTo($directory)) {
                throw BackupCorrupt::unreadable('the inner archive refused to unpack');
            }
        } finally {
            $zip->close();
        }

        $raw = file_get_contents($directory.DIRECTORY_SEPARATOR.'manifest.json');

        if ($raw === false) {
            throw BackupCorrupt::unreadable('the archive has no manifest, so there is nothing to check its tables against');
        }

        /** @var mixed $manifest */
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw BackupCorrupt::unreadable('the manifest is not an object');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{kind: string, detail: string}>  $findings
     */
    private function checkManifest(array $manifest, array &$findings): void
    {
        if (($manifest['format'] ?? null) !== 1) {
            $findings[] = ['kind' => 'format', 'detail' => 'the manifest format is not 1, so this reader may not understand it'];
        }

        if (($manifest['app'] ?? null) !== 'fleet-guard') {
            $findings[] = ['kind' => 'format', 'detail' => 'the manifest does not name fleet-guard as its writer'];
        }

        if (($manifest['key_fingerprint'] ?? null) !== $this->cipher->fingerprint()) {
            $findings[] = [
                'kind' => 'key',
                'detail' => 'the manifest names a different key fingerprint than the one this deployment decrypts with',
            ];
        }

        if (! is_array($manifest['tables'] ?? null)) {
            $findings[] = ['kind' => 'format', 'detail' => 'the manifest has no tables map'];

            return;
        }

        /** @var list<string> $expected */
        $expected = config('fleet.backup.tables');

        foreach (array_keys($manifest['tables']) as $table) {
            if (! is_string($table) || ! in_array($table, $expected, true)) {
                $findings[] = [
                    'kind' => 'table',
                    'detail' => sprintf('the copy carries table "%s", which fleet.backup.tables does not name; it will not be replayed', (string) $table),
                ];
            }
        }

        foreach ($expected as $table) {
            if (! array_key_exists($table, $manifest['tables'])) {
                $findings[] = ['kind' => 'table', 'detail' => "fleet.backup.tables names {$table}, but the copy does not carry it"];
            }
        }
    }

    /**
     * Prove the payload against its own manifest before any of it reaches a database.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array{kind: string, detail: string}>  $findings
     */
    private function checkPayload(array $manifest, string $directory, array &$findings): void
    {
        foreach ((array) $manifest['tables'] as $table => $entry) {
            if (! is_string($table) || ! is_array($entry)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.'tables'.DIRECTORY_SEPARATOR.$table.'.csv.gz';

            if (! is_file($path)) {
                $findings[] = ['kind' => 'missing', 'detail' => "{$table}: the manifest names it but the archive does not contain it"];

                continue;
            }

            $digest = hash_file('sha256', $path);

            if ($digest !== ($entry['sha256'] ?? null)) {
                $findings[] = ['kind' => 'digest', 'detail' => "{$table}: the compressed CSV's sha256 is not the one the manifest recorded"];

                continue;
            }

            $size = filesize($path);

            if ($size !== (int) ($entry['bytes'] ?? -1)) {
                $findings[] = ['kind' => 'size', 'detail' => "{$table}: the compressed CSV is {$size} bytes, the manifest says {$entry['bytes']}"];
            }

            $count = $this->countRows($path);

            if ($count === null) {
                $findings[] = ['kind' => 'read', 'detail' => "{$table}: the compressed CSV would not decompress"];

                continue;
            }

            if ($count !== (int) ($entry['rows'] ?? -1)) {
                $findings[] = ['kind' => 'rows', 'detail' => "{$table}: {$count} data rows unpack, the manifest recorded {$entry['rows']}"];
            }
        }
    }

    private function countRows(string $path): ?int
    {
        $handle = fopen('compress.zlib://'.$path, 'rb');

        if ($handle === false) {
            return null;
        }

        $rows = 0;

        try {
            fgetcsv($handle, escape: '\\');

            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                if ($row === [null]) {
                    continue;
                }

                $rows++;
            }
        } catch (Throwable) {
            return null;
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array{mode: string, name: string, tables: list<string>}  $namespace
     * @param  array<string, mixed>  $manifest
     * @param  list<array{kind: string, detail: string}>  $findings
     */
    private function load(array &$namespace, array $manifest, string $directory, array &$findings): void
    {
        foreach ((array) $manifest['tables'] as $table => $entry) {
            if (! is_string($table) || ! is_array($entry)) {
                continue;
            }

            if (! in_array($table, (array) config('fleet.backup.tables'), true)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.'tables'.DIRECTORY_SEPARATOR.$table.'.csv.gz';
            $columns = array_values(array_map('strval', (array) ($entry['columns'] ?? [])));

            if (! is_file($path) || $columns === []) {
                continue;
            }

            if (! $this->createTable($namespace, $table, (string) ($entry['ddl'] ?? ''), $findings)) {
                continue;
            }

            $handle = fopen('compress.zlib://'.$path, 'rb');

            if ($handle === false) {
                $findings[] = ['kind' => 'read', 'detail' => "{$table}: the copy would not open for loading"];

                continue;
            }

            try {
                $header = fgetcsv($handle, escape: '\\');

                if (! is_array($header) || array_values(array_map('strval', $header)) !== $columns) {
                    $findings[] = ['kind' => 'shape', 'detail' => "{$table}: the CSV header is not the column list the manifest names"];
                }

                $batch = [];

                while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                    if ($row === [null]) {
                        continue;
                    }

                    if (count($row) !== count($columns)) {
                        $findings[] = ['kind' => 'shape', 'detail' => "{$table}: a row has ".count($row).' values for '.count($columns).' columns'];

                        continue;
                    }

                    $values = array_map(static fn (mixed $value): ?string => $value === null || $value === '\\N' ? null : (string) $value, $row);
                    $batch[] = array_combine($columns, $values);

                    if (count($batch) >= self::BATCH) {
                        DB::table($this->qualified($namespace, $table))->insert($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    DB::table($this->qualified($namespace, $table))->insert($batch);
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Replay the recorded definition into the scratch namespace with its foreign keys removed.
     *
     * The copy is a books copy, not a cluster: the restored journal's `company_id` references
     * `companies`, which is not in the export, so a faithful replay would refuse the very rows the
     * drill exists to load. Dropping the keys changes nothing about the data being proven — the
     * counts and the trial balance are computed from the rows themselves.
     *
     * @param  array{mode: string, name: string, tables: list<string>}  $namespace
     * @param  list<array{kind: string, detail: string}>  $findings
     */
    private function createTable(array &$namespace, string $table, string $ddl, array &$findings): bool
    {
        if ($ddl === '' || ! preg_match('/^CREATE TABLE `([^`]+)`/', $ddl, $match) || $match[1] !== $table) {
            $findings[] = ['kind' => 'ddl', 'detail' => "{$table}: the recorded definition does not start with CREATE TABLE for that table"];

            return false;
        }

        // The replacement target is built from a name this class minted, never from the archive.
        $target = $namespace['mode'] === 'schema'
            ? 'CREATE TABLE `'.$namespace['name'].'`.`'.$table.'`'
            : 'CREATE TABLE `'.$namespace['name'].$table.'`';

        $statement = (string) preg_replace('/^CREATE TABLE `([^`]+)`/', $target, $ddl, 1);
        $statement = (string) preg_replace('/^[ \t]*CONSTRAINT `[^`]+` FOREIGN KEY [^\r\n]*\r?\n/mi', '', $statement);
        // CHECK constraint names are schema-wide in MySQL, not table-wide: replaying the live names
        // into the application's own schema collides with them, and letting the server mint names
        // pushes the identifier past 64 characters for a long table name. A short random name is
        // unique in the schema and leaves the table name its full budget.
        $statement = (string) preg_replace_callback(
            '/CONSTRAINT `[^`]+` (CHECK\b)/i',
            static fn (array $match): string => 'CONSTRAINT `fgchk_'.Str::lower(Str::random(10)).'` '.$match[1],
            $statement,
        );
        // Removing the last constraint leaves a comma before the closing parenthesis.
        $statement = (string) preg_replace('/,[ \t]*\r?\n\)/', "\n)", $statement);

        try {
            DB::statement($statement);
        } catch (Throwable $exception) {
            $findings[] = ['kind' => 'ddl', 'detail' => "{$table}: the recorded definition would not create — ".$exception->getMessage()];

            return false;
        }

        $namespace['tables'][] = $this->qualified($namespace, $table);

        return true;
    }

    /** @param array{mode: string, name: string} $namespace */
    private function qualified(array $namespace, string $table): string
    {
        return $namespace['mode'] === 'schema' ? $namespace['name'].'.'.$table : $namespace['name'].$table;
    }

    /**
     * @param  array{mode: string, name: string}  $namespace
     * @param  array<string, mixed>  $manifest
     * @param  list<array{kind: string, detail: string}>  $findings
     * @return array{tables: int, rows: int}
     */
    private function compareCounts(array $namespace, array $manifest, array &$findings): array
    {
        $tables = 0;
        $rows = 0;

        foreach ((array) $manifest['tables'] as $table => $entry) {
            if (! is_string($table) || ! is_array($entry) || ! in_array($table, (array) config('fleet.backup.tables'), true)) {
                continue;
            }

            $expected = (int) ($entry['rows'] ?? 0);

            try {
                $actual = (int) DB::table($this->qualified($namespace, $table))->count();
            } catch (Throwable $exception) {
                $findings[] = ['kind' => 'restore', 'detail' => "{$table}: could not be counted after the restore — ".$exception->getMessage()];

                continue;
            }

            $tables++;
            $rows += $actual;

            if ($actual !== $expected) {
                $findings[] = ['kind' => 'restore', 'detail' => "{$table}: {$actual} rows restored, the manifest recorded {$expected}"];
            }
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * The figure the books are judged by: debit equals credit, per company, matching what was exported.
     *
     * @param  array{mode: string, name: string}  $namespace
     * @param  array<string, mixed>  $manifest
     * @param  list<array{kind: string, detail: string}>  $findings
     */
    private function compareTrialBalance(array $namespace, array $manifest, array &$findings): void
    {
        /** @var array{lines: string, entries: string, amount: string, direction: string, debit: string, credit: string} $config */
        $config = config('fleet.backup.trial_balance');

        /** @var array<string, array{debit: int, credit: int, lines: int}> $expected */
        $expected = is_array($manifest['trial_balance'] ?? null) ? $manifest['trial_balance'] : [];

        try {
            // Same statement shape as the exporter, fully qualified with the scratch namespace; the
            // direction values stay bindings while the configured table and column names are data.
            $sql = 'select e.company_id as company, '
                .'sum(case when l.`'.$config['direction'].'` = ? then l.`'.$config['amount'].'` else 0 end) as debit, '
                .'sum(case when l.`'.$config['direction'].'` = ? then l.`'.$config['amount'].'` else 0 end) as credit, '
                .'count(*) as line_count'
                .' from '.$this->qualified($namespace, $config['lines']).' as l'
                .' join '.$this->qualified($namespace, $config['entries']).' as e on e.id = l.entry_id'
                .' group by e.company_id';

            /** @var list<object{company: int|string, debit: int|string, credit: int|string, line_count: int|string}> $rows */
            $rows = DB::select($sql, [$config['debit'], $config['credit']]);
        } catch (Throwable $exception) {
            // The restore already reported what could not be loaded; a missing journal table must
            // reach the operator as that finding, not as a stack trace over the top of it.
            $findings[] = [
                'kind' => 'trial_balance',
                'detail' => 'the trial balance could not be read from the restored rows — '.$exception->getMessage(),
            ];

            return;
        }

        $actual = [];

        foreach ($rows as $row) {
            $actual[(string) $row->company] = [
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                'lines' => (int) $row->line_count,
            ];
        }

        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $company) {
            $want = $expected[$company] ?? ['debit' => 0, 'credit' => 0, 'lines' => 0];
            $got = $actual[$company] ?? ['debit' => 0, 'credit' => 0, 'lines' => 0];

            foreach (['debit', 'credit', 'lines'] as $figure) {
                if ($want[$figure] !== $got[$figure]) {
                    $findings[] = [
                        'kind' => 'trial_balance',
                        'company' => (int) $company,
                        'detail' => sprintf(
                            'company %s: restored %s %d, the export recorded %d',
                            $company,
                            $figure,
                            $got[$figure],
                            $want[$figure],
                        ),
                    ];
                }
            }

            if ($got['debit'] !== $got['credit']) {
                $findings[] = [
                    'kind' => 'unbalanced',
                    'company' => (int) $company,
                    'detail' => sprintf('company %s: the restored journal does not balance — debit %d, credit %d', $company, $got['debit'], $got['credit']),
                ];
            }
        }
    }

    /** @return array{mode: string, name: string, tables: list<string>} */
    private function scratchNamespace(): array
    {
        $prefix = (string) config('fleet.backup.drill.schema_prefix');
        $name = $prefix.now()->format('Ymd_His').'_'.Str::lower(Str::random(6));

        if (! preg_match('/^'.preg_quote($prefix, '/').'[a-z0-9_]+$/', $name)) {
            throw new RuntimeException("The drill namespace {$name} does not carry the configured prefix {$prefix}.");
        }

        return ['mode' => 'schema', 'name' => $name, 'tables' => []];
    }

    /**
     * Take a dedicated schema if the server allows one, otherwise rename the namespace into a table
     * prefix inside the application's own schema.
     *
     * The fallback does not weaken the proof: the DDL, the rows, the counts and the trial balance are
     * identical. What changes is only where they live for the length of the drill, and a managed
     * database that grants DML and `CREATE TABLE` but not `CREATE DATABASE` — Laravel Cloud's user is
     * that shape — would otherwise be unable to run the quarterly obligation at all.
     *
     * @param  array{mode: string, name: string, tables: list<string>}  $namespace
     */
    private function createNamespace(array &$namespace): void
    {
        $collation = (string) config('database.connections.'.config('database.default').'.collation', 'utf8mb4_0900_ai_ci');

        try {
            DB::statement('create database `'.$namespace['name'].'` character set utf8mb4 collate '.$collation);

            return;
        } catch (Throwable) {
            // Expected on a managed server: continue with prefixed tables.
        }

        $longest = max([0, ...array_map(static fn (mixed $table): int => strlen((string) $table), (array) config('fleet.backup.tables'))]);
        $room = 63 - $longest;

        if (strlen($namespace['name']) > $room) {
            $namespace['name'] = substr($namespace['name'], 0, max(1, $room));
        }

        $namespace['mode'] = 'prefix';
        $namespace['name'] .= '_';
    }

    /**
     * Drop only what this class minted: the schema it created, or the prefixed tables it created.
     *
     * @param  array{mode: string, name: string, tables: list<string>}  $namespace
     */
    private function dropNamespace(array $namespace): bool
    {
        $prefix = (string) config('fleet.backup.drill.schema_prefix');
        $name = rtrim($namespace['name'], '_');

        if (! str_starts_with($name, $prefix) || ! preg_match('/^[a-z0-9_]+$/', $name)) {
            return false;
        }

        try {
            if ($namespace['mode'] === 'schema') {
                DB::statement('drop database if exists `'.$namespace['name'].'`');
            } else {
                foreach ($namespace['tables'] as $table) {
                    DB::statement('drop table if exists `'.$table.'`');
                }
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('fleet.backup.disk'));
    }

    private function workDirectory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fg-restore-'.Str::lower(Str::random(10));

        if (! mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create the restore work directory {$path}.");
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
                // Best effort: a decrypted copy that refuses deletion must not mask the drill's report,
                // and the OS reclaims the temp directory.
            }
        }

        @rmdir($path);
    }
}
