<?php

declare(strict_types=1);

namespace App\Domain\Security\DataGovernance\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\DataGovernance\FieldCipher;
use App\Domain\Security\DataGovernance\MissingFieldKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * / 'S key rotation: move every column ciphertext onto the configured current key version,
 * one row at a time, so changing `DB_CRYPT_KEY_VERSION` is a resumable job rather than a data-loss
 * event.
 *
 * The discovery is by schema shape, not by a table list: any table with a `key_version` column and a
 * `*_encrypted` column is a target. A hardcoded list would silently stop covering the next encrypted
 * column somebody adds, and "the rotation forgot your column" is invisible until the old key is
 * retired.
 *
 * Two rules make the run safe to repeat and safe to interrupt:
 *
 * 1. **Every payload is decrypted with the version recorded on its own row** — never "the previous
 *    version". After a partially completed run the table holds a mix, and only the row knows which
 *    key made it.
 * 2. **Rows already on the current version are skipped**, so running the job twice is a no-op rather
 *    than a full rewrite. That also makes the progress a number an operator can watch (`pending`).
 *
 * A row that will not decrypt is counted as a failure and left exactly as it was. Aborting the whole
 * run on one bad row would block the rotation of every readable row beside it; aborting *before* any
 * write is reserved for a missing key, which no amount of retrying can fix.
 *
 * Writes go through the query builder on purpose: rotation is not a business edit, so it must not
 * touch `updated_at`, fire model events, or pass through the encryption trait that would re-read the
 * key version it is in the middle of changing.
 */
final class RotateFieldKeys
{
    public function __construct(
        private readonly FieldCipher $cipher,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     to: string,
     *     from: list<string>,
     *     tables: int,
     *     rows: int,
     *     pending: int,
     *     rotated: int,
     *     failed: int,
     *     findings: list<array{table: string, id: string, column: string, finding: string}>,
     *     per_table: array<string, array{rows: int, pending: int, rotated: int, failed: int}>
     * }
     *
     * @throws MissingFieldKey when a version the data needs is not in the key ring
     */
    public function __invoke(?int $companyId = null, bool $dryRun = false, int $batch = 500): array
    {
        $current = $this->cipher->currentVersion();

        if (! $this->cipher->supports($current)) {
            throw MissingFieldKey::forVersion($current);
        }

        $targets = $this->targets();

        $this->assertEveryVersionIsReadable($targets, $companyId);

        $report = [
            'dry_run' => $dryRun,
            'to' => $current,
            'from' => [],
            'tables' => count($targets),
            'rows' => 0,
            'pending' => 0,
            'rotated' => 0,
            'failed' => 0,
            'findings' => [],
            'per_table' => [],
        ];

        $versions = [];
        $rotatedByCompany = [];

        foreach ($targets as $target) {
            $result = $this->rotateTable($target, $companyId, $dryRun, $batch, $current);

            $report['per_table'][$target['table']] = $result['stats'];
            $report['rows'] += $result['stats']['rows'];
            $report['pending'] += $result['stats']['pending'];
            $report['rotated'] += $result['stats']['rotated'];
            $report['failed'] += $result['stats']['failed'];
            $report['findings'] = array_merge($report['findings'], $result['findings']);

            foreach ($result['versions'] as $version) {
                $versions[$version] = true;
            }

            foreach ($result['rotated_by_company'] as $company => $rows) {
                $rotatedByCompany[$company] = ($rotatedByCompany[$company] ?? 0) + $rows;
            }
        }

        $report['from'] = array_keys($versions);
        sort($report['from']);

        if (! $dryRun && $report['rotated'] > 0) {
            $this->record($report['from'], $current, $report['tables'], $rotatedByCompany);
        }

        return $report;
    }

    /**
     * Every table that stores a payload: a `key_version` column and at least one `*_encrypted`
     * column. `id` is required because batching has to have a stable cursor; a table without one is
     * reported rather than guessed at.
     *
     * @return list<array{table: string, columns: list<string>, company_scoped: bool, has_id: bool}>
     */
    private function targets(): array
    {
        /** @var list<object{t: string, c: string}> $rows */
        $rows = DB::select(
            'select table_name as t, column_name as c
               from information_schema.columns
              where table_schema = database()
              order by table_name, ordinal_position'
        );

        /** @var array<string, list<string>> $columns */
        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->t][] = (string) $row->c;
        }

        $targets = [];

        foreach ($columns as $table => $names) {
            if (! in_array('key_version', $names, true)) {
                continue;
            }

            /** @var list<string> $encrypted */
            $encrypted = array_values(array_filter(
                $names,
                static fn (string $column): bool => str_ends_with($column, '_encrypted'),
            ));

            if ($encrypted === []) {
                continue;
            }

            $targets[] = [
                'table' => $table,
                'columns' => $encrypted,
                'company_scoped' => in_array('company_id', $names, true),
                'has_id' => in_array('id', $names, true),
            ];
        }

        return $targets;
    }

    /**
     * @param  list<array{table: string, columns: list<string>, company_scoped: bool, has_id: bool}>  $targets
     *
     * @throws MissingFieldKey
     */
    private function assertEveryVersionIsReadable(array $targets, ?int $companyId): void
    {
        foreach ($targets as $target) {
            foreach ($target['columns'] as $column) {
                $query = DB::table($target['table'])
                    ->whereNotNull($column)
                    ->whereNotNull('key_version');

                if ($companyId !== null && $target['company_scoped']) {
                    $query->where('company_id', $companyId);
                }

                /** @var list<string> $versions */
                $versions = $query->distinct()->pluck('key_version')
                    ->map(static fn (mixed $version): string => (string) $version)
                    ->all();

                foreach ($versions as $version) {
                    if (! $this->cipher->supports($version)) {
                        throw MissingFieldKey::forVersion($version);
                    }
                }
            }
        }
    }

    /**
     * @param  array{table: string, columns: list<string>, company_scoped: bool, has_id: bool}  $target
     * @return array{
     *     stats: array{rows: int, pending: int, rotated: int, failed: int},
     *     findings: list<array{table: string, id: string, column: string, finding: string}>,
     *     versions: list<string>,
     *     rotated_by_company: array<int, int>
     * }
     */
    private function rotateTable(
        array $target,
        ?int $companyId,
        bool $dryRun,
        int $batch,
        string $current,
    ): array {
        $stats = ['rows' => 0, 'pending' => 0, 'rotated' => 0, 'failed' => 0];
        $findings = [];
        $versions = [];
        $rotatedByCompany = [];

        if (! $target['has_id']) {
            return [
                'stats' => $stats,
                'findings' => [[
                    'table' => $target['table'],
                    'id' => '',
                    'column' => implode(', ', $target['columns']),
                    'finding' => 'the table has no id column, so rotation has no stable cursor',
                ]],
                'versions' => [],
                'rotated_by_company' => [],
            ];
        }

        if ($companyId !== null && ! $target['company_scoped']) {
            return [
                'stats' => $stats,
                'findings' => [[
                    'table' => $target['table'],
                    'id' => '',
                    'column' => implode(', ', $target['columns']),
                    'finding' => 'the table has no company_id, so --company cannot scope it',
                ]],
                'versions' => [],
                'rotated_by_company' => [],
            ];
        }

        /** @var list<string> $select */
        $select = ['id', 'key_version', ...($target['company_scoped'] ? ['company_id'] : []), ...$target['columns']];

        $query = DB::table($target['table'])->select($select);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $query->where(function ($where) use ($target): void {
            foreach ($target['columns'] as $column) {
                $where->orWhereNotNull($column);
            }
        });

        $query->chunkById($batch, function ($rows) use (
            &$stats,
            &$findings,
            &$versions,
            &$rotatedByCompany,
            $dryRun,
            $current,
            $target,
        ): void {
            foreach ($rows as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;

                if (! $this->holdsCiphertext($values, $target['columns'])) {
                    continue;
                }

                $stats['rows']++;

                $version = is_string($values['key_version'] ?? null) && $values['key_version'] !== ''
                    ? $values['key_version']
                    : null;

                if ($version === $current) {
                    continue;
                }

                $stats['pending']++;

                if ($version === null) {
                    $stats['failed']++;
                    $findings[] = [
                        'table' => $target['table'],
                        'id' => (string) $values['id'],
                        'column' => implode(', ', $target['columns']),
                        'finding' => 'carries ciphertext but names no key_version, so no key can be selected for it',
                    ];

                    continue;
                }

                $versions[$version] = true;

                if ($dryRun) {
                    continue;
                }

                try {
                    $update = $this->reseal($values, $target['columns'], $version, $current);
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    $findings[] = [
                        'table' => $target['table'],
                        'id' => (string) $values['id'],
                        'column' => implode(', ', $target['columns']),
                        'finding' => 'could not be re-encrypted: '.$exception->getMessage(),
                    ];

                    continue;
                }

                $update['key_version'] = $current;

                DB::table($target['table'])->where('id', $values['id'])->update($update);

                $stats['rotated']++;

                if (isset($values['company_id'])) {
                    $company = (int) $values['company_id'];
                    $rotatedByCompany[$company] = ($rotatedByCompany[$company] ?? 0) + 1;
                }
            }
        });

        return [
            'stats' => $stats,
            'findings' => $findings,
            'versions' => array_keys($versions),
            'rotated_by_company' => $rotatedByCompany,
        ];
    }

    /**
     * Decrypt with the row's own version, seal with the current one, and read the new payload back
     * before it is written. The round trip is the check that matters: an Encrypter that cannot read
     * its own output would otherwise replace ciphertext nobody can decrypt.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function reseal(array $values, array $columns, string $from, string $to): array
    {
        $update = [];

        foreach ($columns as $column) {
            $payload = $values[$column] ?? null;

            if (! is_string($payload) || $payload === '') {
                continue;
            }

            $plaintext = $this->cipher->decrypt($payload, $from);
            $resealed = $this->cipher->encrypt($plaintext);

            if ($this->cipher->decrypt($resealed, $to) !== $plaintext) {
                throw new RuntimeException("the payload in {$column} did not survive the re-encryption round trip");
            }

            $update[$column] = $resealed;
        }

        return $update;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $columns
     */
    private function holdsCiphertext(array $values, array $columns): bool
    {
        foreach ($columns as $column) {
            $payload = $values[$column] ?? null;

            if (is_string($payload) && $payload !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One row per company whose data moved. The event is written after the writes, pinned to the
     * company it describes, and records the version pair rather than the payloads.
     *
     * @param  list<string>  $from
     * @param  array<int, int>  $rotatedByCompany
     */
    private function record(array $from, string $to, int $tables, array $rotatedByCompany): void
    {
        $previous = CurrentCompany::pin();

        try {
            foreach ($rotatedByCompany as $companyId => $rows) {
                CurrentCompany::force($companyId);

                $this->audit->security(SecurityEventKind::KeyRotated, [
                    'from_versions' => $from,
                    'to_version' => $to,
                    'tables' => $tables,
                    'rows' => $rows,
                ]);
            }
        } finally {
            CurrentCompany::force($previous);
        }
    }
}
