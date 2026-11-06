<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Alerts\Support\SelfCheckAlerter;
use App\Domain\Kernel\Tenancy\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The performance review as a command, for the same reason the review is one: a checklist in a
 * document is a checklist nobody runs on the release that needed it.
 *
 * Four questions, answered from the runtime and the schema:
 *
 * 1. **Is the N+1 guard on?** The design asks for `preventLazyLoading()` outside production, and a
 *    provider that stops calling it is invisible to every page test until the first slow afternoon.
 *    In production the guard is off by design; that is a note, not a finding.
 * 2. **Does every page have a budget?** `config/performance.php` maps each page route to a query and
 *    millisecond ceiling. A new screen with no entry is the finding — a budget nobody wrote is a
 *    budget nobody checks. Registers must also paginate in the query, not load every row and slice.
 * 3. **Is every foreign key indexed, and does every tenant-scoped table lead with `company_id`?**
 *    The T1 rule and the "indexes on every FK and every filtered column", read from
 *    `information_schema` rather than from the migrations that were supposed to create them.
 * 4. **Are the ledger indexes and the GPS shapes still there?** The ledger's reports are
 *    range scans over these exact columns; a dropped composite index changes the plan, not the answer,
 *    so nothing but schema introspection notices.
 *
 * `--static` skips the two schema answers so CI can run the same command without a database. Like
 * `security:audit`, that mode also skips alerting: "database unreachable" is the one failure the
 * alert engine cannot record in a database.
 */
final class PerformanceAudit extends Command
{
    protected $signature = 'performance:audit
        {--static : Skip database-backed index checks — the mode CI runs without an application schema}
        {--json : Print one machine-readable object instead of the report}
        {--no-alert : Report findings without touching the alert register}';

    protected $description = 'Audit page budgets, the N+1 guard and index coverage';

    /** @var list<array{check: string, subject: string, finding: string}> */
    private array $findings = [];

    /** @var list<array{check: string, subject: string, note: string}> */
    private array $notes = [];

    /** @var array<string, string|int> */
    private array $summary = [];

    public function handle(SelfCheckAlerter $alerter): int
    {
        $this->checkLazyLoadingGuard();
        $this->checkBudgets();

        if ($this->option('static')) {
            $this->summary['indexes'] = 'skipped (static mode)';
        } else {
            $this->checkIndexes();
        }

        $this->report();

        if ($this->findings !== [] && ! $this->option('no-alert') && ! $this->option('static')) {
            $this->raise($alerter);
        } elseif ($this->findings === [] && ! $this->option('no-alert') && ! $this->option('static')) {
            $alerter->clear('performance:audit', 'Page budgets, the N+1 guard and index coverage are intact.');
        }

        return $this->findings === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkLazyLoadingGuard(): void
    {
        $enabled = Model::preventsLazyLoading();
        $providerHasIt = str_contains(
            (string) File::get(app_path('Providers/AppServiceProvider.php')),
            'preventLazyLoading',
        );

        if (! $enabled && ! app()->isProduction()) {
            $this->finding('lazy-loading', 'AppServiceProvider', 'preventLazyLoading() is off outside production, so a missing eager load is silent');
        }

        if (! $providerHasIt) {
            $this->finding('lazy-loading', 'AppServiceProvider', 'nothing in the provider turns the N+1 guard on; the flag has no owner');
        }

        if ($enabled && app()->isProduction()) {
            $this->note('lazy-loading', 'production', 'the guard is off in production by design (outside production)');
        }

        $this->summary['lazy'] = $enabled ? 'N+1 guard on' : 'N+1 guard off';
    }

    private function checkBudgets(): void
    {
        /** @var array<string, array{queries?: mixed, ms?: mixed, register?: mixed}> $budgets */
        $budgets = (array) config('performance.pages', []);
        /** @var array<string, string> $excluded */
        $excluded = (array) config('performance.excluded', []);

        $pages = $this->pageRoutes();
        $covered = 0;
        $unbudgeted = 0;
        $excludedPages = 0;

        foreach ($pages as $name => $component) {
            if (isset($budgets[$name])) {
                $covered++;

                continue;
            }

            if ($this->excluded($name, $excluded)) {
                $excludedPages++;

                continue;
            }

            $unbudgeted++;
            $this->finding('budgets', $name, "page route has no entry in config/performance.php (component {$component})");
        }

        $broken = 0;

        foreach ($budgets as $name => $budget) {
            if (! isset($pages[$name])) {
                $broken++;
                $this->finding('budgets', $name, 'budget names a route that is no longer a page; a stale ceiling checks nothing');

                continue;
            }

            $queries = $budget['queries'] ?? null;
            $ms = $budget['ms'] ?? null;

            if (! is_int($queries) || $queries < 1) {
                $broken++;
                $this->finding('budgets', $name, 'queries ceiling must be a positive integer');
            }

            if (! is_int($ms) || $ms < 1) {
                $broken++;
                $this->finding('budgets', $name, 'millisecond ceiling must be a positive integer');
            }

            if (($budget['register'] ?? false) === true) {
                $file = $this->componentFile((string) $pages[$name]);

                if ($file === null) {
                    $broken++;
                    $this->finding('budgets', $name, 'register component file not found, so pagination cannot be checked');
                } elseif (! $this->paginates($file)) {
                    $broken++;
                    $this->finding('budgets', $name, 'register loads its rows without paginate()/cursorPaginate(); The design forbids an unbounded list');
                }
            }
        }

        $this->summary['budgets'] = sprintf(
            '%d page(s), %d budgeted, %d excluded by design, %d unbudgeted, %d broken',
            count($pages),
            $covered,
            $excludedPages,
            $unbudgeted,
            $broken,
        );
    }

    /**
     * @return array<string, string> route name => Livewire component
     */
    private function pageRoutes(): array
    {
        $pages = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $component = $route->getAction('livewire_component');

            if (! is_string($component) || ! str_starts_with($component, 'pages::')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            if (is_string($name) && $name !== '') {
                $pages[$name] = $component;
            }
        }

        return $pages;
    }

    /**
     * @param  array<string, string>  $excluded
     */
    private function excluded(string $name, array $excluded): bool
    {
        foreach (array_keys($excluded) as $pattern) {
            if ($pattern === $name) {
                return true;
            }

            if (str_ends_with($pattern, '.*') && str_starts_with($name, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private function componentFile(string $component): ?string
    {
        $relative = str_replace(['pages::', '.'], ['', '/'], $component);
        $directory = resource_path('views/pages/'.dirname($relative));
        $base = basename($relative);

        foreach ([$directory.'/⚡'.$base.'.blade.php', $directory.'/'.$base.'.blade.php'] as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function paginates(string $file): bool
    {
        $source = (string) File::get($file);

        return str_contains($source, 'paginate(')
            || str_contains($source, 'cursorPaginate(')
            || str_contains($source, 'new Pagination(');
    }

    private function checkIndexes(): void
    {
        $connection = DB::connection();

        try {
            $schema = $connection->getDatabaseName();
            /** @var list<object{t: string}> $tables */
            $tables = $connection->select(
                'select table_name as t from information_schema.tables where table_schema = ? and table_type = ? order by table_name',
                [$schema, 'BASE TABLE'],
            );
        } catch (\Throwable $unreachable) {
            $this->finding('indexes', 'database', 'the schema could not be read: '.$unreachable->getMessage());
            $this->summary['indexes'] = 'unreadable';

            return;
        }

        $names = array_map(static fn (object $row): string => (string) $row->t, $tables);
        $indexes = [];

        foreach ($names as $table) {
            $rows = $connection->select(
                'select index_name as n, seq_in_index as s, non_unique as u, column_name as c
                   from information_schema.statistics
                  where table_schema = ? and table_name = ?
                  order by index_name, seq_in_index',
                [$schema, $table],
            );

            /** @var array<string, array<int|'unique', string|bool>> $groups */
            $groups = [];

            foreach ($rows as $row) {
                $groups[(string) $row->n][(int) $row->s] = (string) $row->c;
                $groups[(string) $row->n]['unique'] ??= ((int) $row->u) === 0;
            }

            ksort($groups);

            $indexes[$table] = array_map(
                static function (array $columns): array {
                    $unique = $columns['unique'] === true;
                    unset($columns['unique']);
                    ksort($columns);

                    return ['columns' => array_values($columns), 'unique' => $unique];
                },
                $groups,
            );
        }

        $tenantTables = 0;
        $companyFailures = 0;
        $fkFailures = 0;

        foreach ($indexes as $table => $shapes) {
            $hasCompany = false;

            foreach ($shapes as $shape) {
                if (($shape['columns'][0] ?? null) === 'company_id') {
                    $hasCompany = true;

                    break;
                }
            }

            $companyColumn = $connection->selectOne(
                'select count(*) as n from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
                [$schema, $table, 'company_id'],
            );

            if ((int) ($companyColumn->n ?? 0) === 0) {
                continue;
            }

            $tenantTables++;

            if (! $hasCompany) {
                $companyFailures++;
                $this->finding('indexes', $table, 'has company_id but no index leading with it (T1)');
            }
        }

        $foreignKeys = $connection->select(
            'select table_name as t, column_name as c, referenced_table_name as r
               from information_schema.key_column_usage
              where table_schema = ? and referenced_table_name is not null
              order by table_name, column_name',
            [$schema],
        );

        foreach ($foreignKeys as $fk) {
            $table = (string) $fk->t;
            $column = (string) $fk->c;
            $covered = false;

            foreach ($indexes[$table] ?? [] as $shape) {
                $columns = $shape['columns'];

                if (($columns[0] ?? null) === $column) {
                    $covered = true;

                    break;
                }

                if (($columns[0] ?? null) === 'company_id' && ($columns[1] ?? null) === $column) {
                    $covered = true;

                    break;
                }
            }

            if (! $covered) {
                $fkFailures++;
                $this->finding('indexes', $table.'.'.$column, 'foreign key to '.$fk->r.' has no index that can serve a scoped lookup');
            }
        }

        $requiredFailures = 0;

        // The shapes and the design promise live in `config/fleet.php` under
        // `performance.required_indexes`: they name ledger and telematics tables, and D3 keeps those
        // names out of `app/` so the posting door stays the only code that speaks them.
        /** @var list<array{table: string, columns: list<string>, unique: bool, why: string}> $requiredIndexes */
        $requiredIndexes = (array) config('fleet.performance.required_indexes', []);

        foreach ($requiredIndexes as $required) {
            $table = $required['table'];

            if (! isset($indexes[$table])) {
                $requiredFailures++;
                $this->finding('indexes', $table, 'the table the design names does not exist');

                continue;
            }

            $found = false;

            foreach ($indexes[$table] as $shape) {
                if ($shape['columns'] !== $required['columns']) {
                    continue;
                }

                if ($required['unique'] && ! $shape['unique']) {
                    continue;
                }

                $found = true;

                break;
            }

            if (! $found) {
                $requiredFailures++;
                $this->finding(
                    'indexes',
                    $table.'('.implode(', ', $required['columns']).')',
                    ($required['unique'] ? 'unique ' : '').'index missing — '.$required['why'],
                );
            }
        }

        $this->summary['indexes'] = sprintf(
            '%d tables, %d tenant-scoped, %d FK(s), %s',
            count($indexes),
            $tenantTables,
            count($foreignKeys),
            $companyFailures + $fkFailures + $requiredFailures === 0
                ? 'every required index present'
                : ($companyFailures + $fkFailures + $requiredFailures).' index finding(s)',
        );
    }

    private function finding(string $check, string $subject, string $finding): void
    {
        $this->findings[] = ['check' => $check, 'subject' => $subject, 'finding' => $finding];
    }

    private function note(string $check, string $subject, string $note): void
    {
        $this->notes[] = ['check' => $check, 'subject' => $subject, 'note' => $note];
    }

    private function report(): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $this->findings === [],
                'summary' => $this->summary,
                'findings' => $this->findings,
                'notes' => $this->notes,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->newLine();
        $this->line('<options=bold>performance:audit</> — budgets, N+1 guard and indexes');

        foreach (['lazy', 'budgets', 'indexes'] as $section) {
            $this->line(sprintf('  %-12s %s', $section, $this->summary[$section] ?? 'not run'));
        }

        if ($this->notes !== []) {
            $this->newLine();

            foreach ($this->notes as $note) {
                $this->line(sprintf('  <comment>NOTE</comment> %s — %s: %s', $note['subject'], $note['check'], $note['note']));
            }
        }

        if ($this->findings === []) {
            $this->newLine();
            $this->info('  All checks passed.');

            return;
        }

        $this->newLine();

        foreach ($this->findings as $finding) {
            $this->line(sprintf('  <fg=red>FAIL</> %s — %s: %s', $finding['subject'], $finding['check'], $finding['finding']));
        }

        $this->newLine();
        $this->error(sprintf('  %d finding(s). A budget is a ceiling, not a wish.', count($this->findings)));
    }

    private function raise(SelfCheckAlerter $alerter): void
    {
        /** @var list<int> $companies */
        $companies = Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($companies === []) {
            return;
        }

        $details = array_map(
            static fn (array $finding): string => $finding['check'].' '.$finding['subject'].' — '.$finding['finding'],
            $this->findings,
        );

        foreach ($companies as $company) {
            $alerter->record('performance:audit', 'Performance posture needs attention', [$company => $details]);
        }
    }
}
