<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Support\TripCostRollup;
use App\Domain\Operations\TripCostBucket;
use Illuminate\Console\Command;

/**
 * §6.6's proof that a trip's stored money still describes its ledger lines.
 *
 * The rollup is written inside the posting transaction, so drift can only come from a path that
 * posted without asking `RecordTripCost` (a data fix, a Phase 3 fuel issue wired the wrong way, an
 * interrupted migration). This command reads the lines and compares — the same relationship
 * `ledger:verify` checks one level up, on the entries' own totals.
 *
 * Exit code 1 on drift with no `--fix`, so the scheduler can alarm; `--fix` repairs and reports how
 * many rows it rewrote. `ledger_posted_at` is deliberately not compared: it records when the
 * projection was last refreshed, and a clean recost is allowed to move it.
 */
class TripsRecost extends Command
{
    protected $signature = 'trips:recost
        {--fix : Rewrite every drifting trip from its ledger lines}
        {--trip= : One trip by ULID or trip number}
        {--company= : Only this company. Omit to check every company in the database.}
        {--quiet-ok : Print only problems}';

    protected $description = 'Recompute each trip cost rollup from gl_journal_lines and report drift';

    public function handle(TripCostRollup $rollup): int
    {
        $filter = $this->option('trip');
        $company = $this->option('company');
        $checked = 0;
        $drifted = 0;
        $repaired = 0;

        Company::query()
            ->when($company !== null, static fn ($query) => $query->whereKey((int) $company))
            ->orderBy('id')
            ->each(function (Company $owner) use ($rollup, $filter, &$checked, &$drifted, &$repaired): void {
                CurrentCompany::force($owner->id);
                $currency = $owner->base_currency_code;

                try {
                    Trip::query()
                        ->when($filter !== null, static fn ($query) => $query->where(
                            static fn ($inner) => $inner->where('id', (string) $filter)->orWhere('trip_no', (string) $filter),
                        ))
                        ->orderBy('trip_no')
                        ->each(function (Trip $trip) use ($rollup, $currency, &$checked, &$drifted, &$repaired): void {
                            $checked++;
                            $expected = $rollup->compute($trip);

                            if ($this->describes($trip, $expected)) {
                                if (! $this->option('quiet-ok')) {
                                    $this->line(sprintf('   ok   %s  %s', $trip->trip_no, $this->money($expected['contribution_base'], $currency)));
                                }

                                return;
                            }

                            $drifted++;

                            $this->line(sprintf(
                                ' DRIFT  %s  stored %s, ledger says %s',
                                $trip->trip_no,
                                $this->money($trip->contribution_base, $currency),
                                $this->money($expected['contribution_base'], $currency),
                            ));

                            if ($this->option('fix')) {
                                $rollup->write($trip);
                                $repaired++;
                                $this->line(sprintf('  FIXED %s', $trip->trip_no));
                            }
                        });
                } finally {
                    CurrentCompany::forget();
                }
            });

        if ($filter !== null && $checked === 0) {
            $this->error(sprintf('No trip matches "%s" in the companies checked.', (string) $filter));

            return self::FAILURE;
        }

        if ($drifted === 0) {
            $this->info(sprintf('Trip rollups verified: %d trip(s), no drift.', $checked));

            return self::SUCCESS;
        }

        $this->newLine();

        if ($this->option('fix')) {
            $this->info(sprintf('%d drifting trip(s) rewritten from their ledger lines; %d checked.', $repaired, $checked));

            return self::SUCCESS;
        }

        $this->error(sprintf('%d of %d trip(s) disagree with their ledger lines. Re-run with --fix to rewrite them.', $drifted, $checked));

        return self::FAILURE;
    }

    /**
     * @param  array{revenue_base: int, buckets: array<string, int>, direct_cost_base: int, contribution_base: int, line_count: int}  $expected
     */
    private function describes(Trip $trip, array $expected): bool
    {
        if ($trip->revenue_base !== $expected['revenue_base']
            || $trip->direct_cost_base !== $expected['direct_cost_base']
            || $trip->contribution_base !== $expected['contribution_base']) {
            return false;
        }

        foreach (TripCostBucket::cases() as $bucket) {
            if ($trip->getAttribute($bucket->column()) !== $expected['buckets'][$bucket->value]) {
                return false;
            }
        }

        return true;
    }

    private function money(int $minorUnits, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($minorUnits / 100, 2, '.', ' '));
    }
}
