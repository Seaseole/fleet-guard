<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the numbering sequence rows a company needs for its current periods (amended).
 *
 * Runs at install, on deploy, and nightly, so the first document of a new fiscal year finds its
 * row instead of trying to create it under a lock.
 */
class NumberingEnsureSequences extends Command
{
    protected $signature = 'numbering:ensure
        {--company= : Only this company; omit to cover every company in the database}';

    protected $description = 'Create any missing number_sequences rows for the current periods';

    public function handle(NumberSequenceRegistry $registry): int
    {
        $explicit = $this->option('company');

        $companies = $explicit !== null
            ? [(int) $explicit]
            : DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($companies === []) {
            $this->warn('No companies exist yet — run the seeders first.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($companies as $companyId) {
            // Each company's rows are created under its own context, never the CLI user's.
            CurrentCompany::force($companyId);

            $created = $registry->ensureAll();
            $total += count($created);

            $this->line(sprintf(
                'company %d: %s',
                $companyId,
                $created === [] ? 'all sequence rows already present' : 'created '.implode(', ', $created),
            ));
        }

        CurrentCompany::forget();

        $this->newLine();
        $this->line($total === 0
            ? 'Nothing to create — every scope has its row for the current period.'
            : "Created {$total} sequence row(s).");

        return self::SUCCESS;
    }
}
