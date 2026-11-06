<?php

declare(strict_types=1);

use App\Domain\Kernel\Identity\EnsureMultiFactorAuthentication;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The page budgets, measured against the real stack.
 *
 * `performance:audit` reads the schema and insists every page route has a ceiling. This sweep is the
 * other half: it actually opens each page as the Owner on the development database, counts the
 * queries the request caused and the wall time it took, and fails when either passes the ceiling in
 * `config/performance.php`.
 *
 * Not the test suite — tests are local-only by owner decision. It writes nothing: the reads
 * are ordinary GETs, so there is no fixture to clean up. A page whose fixture row is absent on this
 * database is reported as skipped rather than silently passing.
 *
 * Run: FLEET_OWNER_EMAIL=<seat> php scripts/perf-budget.php
 */

require __DIR__.'/../vendor/autoload.php';

final class PageBudgetSweep extends TestCase
{
    public int $failures = 0;

    public int $measured = 0;

    public int $skipped = 0;

    /** @var list<array{page: string, queries: int, ms: float, qlimit: int, mlimit: int}> */
    private array $rows = [];

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function drive(): void
    {
        $this->setUp();

        try {
            $this->sweep();
        } catch (Throwable $blewUp) {
            $this->failures++;
            echo '  FATAL '.get_class($blewUp).' — '.$this->condense($blewUp->getMessage())."\n".$blewUp->getFile().':'.$blewUp->getLine()."\n";
        }

        $this->table();
    }

    private function sweep(): void
    {
        /** @var User|null $owner */
        $owner = User::query()->where('email', config('fleet.identity.owner_email'))->first();

        if ($owner === null) {
            $this->failPage('No Owner row for '.config('fleet.identity.owner_email').' — run FLEET_OWNER_EMAIL=<seat> php artisan fleet:setup-owner (defect d).');

            return;
        }

        CurrentCompany::force((int) $owner->company_id);

        // The MFA gate is proved in render-identity; a budget measures the work after the door.
        $this->withoutMiddleware(EnsureMultiFactorAuthentication::class);
        // The ⚑ prompt is a door too, and this sweep is measuring the screen behind it.
        $this->withSession(['auth.password_confirmed_at' => time()]);

        /** @var array<string, array{queries: int, ms: int, register?: bool}> $budgets */
        $budgets = config('performance.pages', []);

        echo "\nEach page, opened as the Owner: queries and wall time against its ceiling\n";

        foreach ($budgets as $name => $budget) {
            $route = Route::getRoutes()->getByName($name);

            if ($route === null) {
                $this->failPage("{$name} — the budget names a route that does not exist");

                continue;
            }

            $uri = $this->uriFor($route->parameterNames(), $route->getName());

            if ($uri === null) {
                $this->skipped++;
                echo "  skip  {$name} — no fixture row on this database for its path parameters\n";

                continue;
            }

            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $started = hrtime(true);

            try {
                $response = $this->actingAs($owner, 'web')->get($uri);
                $status = $response->getStatusCode();
            } catch (Throwable $exception) {
                DB::connection()->disableQueryLog();
                $this->failPage("{$name} — {$uri} threw ".get_class($exception).': '.$this->condense($exception->getMessage()));

                continue;
            }

            $micros = (hrtime(true) - $started) / 1_000;
            $queries = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            $ms = round($micros / 1_000, 1);
            $this->measured++;

            if ($status !== 200) {
                $this->failPage("{$name} — {$uri} answered {$status}, not 200");

                continue;
            }

            $overQueries = $queries > $budget['queries'];
            $overMs = $ms > $budget['ms'];

            $this->rows[] = [
                'page' => $name,
                'queries' => $queries,
                'ms' => $ms,
                'qlimit' => $budget['queries'],
                'mlimit' => $budget['ms'],
            ];

            if ($overQueries || $overMs) {
                $this->failures++;
                $why = [];
                $overQueries && $why[] = "{$queries} queries over a ceiling of {$budget['queries']}";
                $overMs && $why[] = "{$ms} ms over a ceiling of {$budget['ms']}";
                echo '  FAIL  '.$name.' — '.implode('; ', $why)."\n";

                continue;
            }

            echo "  ok    {$name} — {$queries} queries, {$ms} ms\n";
        }
    }

    /**
     * @param  list<string>  $parameters
     */
    private function uriFor(array $parameters, ?string $name): ?string
    {
        $fixtures = [
            'bill' => 'bills',
            'invoice' => 'invoices',
            'workOrder' => 'work_orders',
            'trip' => 'trips',
            'truck' => 'trucks',
            'customer' => 'customers',
            'employee' => 'employees',
            'driver' => 'drivers',
        ];

        $values = [];

        foreach ($parameters as $parameter) {
            $table = $fixtures[$parameter] ?? null;

            if ($table === null) {
                return null;
            }

            $value = DB::table($table)->orderBy('id')->value('id');

            if ($value === null) {
                return null;
            }

            $values[$parameter] = $value;
        }

        return route((string) $name, $values);
    }

    private function failPage(string $message): void
    {
        $this->failures++;
        echo '  FAIL  '.$message."\n";
    }

    private function table(): void
    {
        if ($this->rows === []) {
            return;
        }

        usort($this->rows, static fn (array $a, array $b): int => $b['queries'] <=> $a['queries']);

        echo "\nThe five heaviest pages, for the record\n";
        printf("  %-34s %8s %10s %8s %10s\n", 'page', 'queries', 'ceiling', 'ms', 'ceiling');

        foreach (array_slice($this->rows, 0, 5) as $row) {
            printf("  %-34s %8d %10d %8.1f %10d\n", $row['page'], $row['queries'], $row['qlimit'], $row['ms'], $row['mlimit']);
        }

        echo sprintf(
            "\n%d measured, %d skipped, %d failure(s)\n",
            $this->measured,
            $this->skipped,
            $this->failures,
        );
    }

    private function condense(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message;

        return mb_substr(trim($message), 0, 220);
    }
}

$sweep = new PageBudgetSweep('sweep');
$sweep->drive();

exit($sweep->failures === 0 ? 0 : 1);
