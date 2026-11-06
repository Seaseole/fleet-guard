<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Models\ScheduledReport;
use App\Domain\Reporting\ReportsCatalogue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The nomination itself ("scheduled email for the reports you nominate").
 *
 * Three refusals, each a sentence rather than a silent filter: only a real report can be
 * nominated, only a *nominated-for-scheduling* one (the trial balance and the P&L have screens
 * and exports — a scheduled copy of a statement nobody asked for is spam with numbers in it),
 * and only by a seat that could export it by hand today. Recipients are not stored: the job
 * resolves them from the export ability at send time, so a revoked role stops the mail without
 * anyone remembering to edit a list.
 */
final class UpsertScheduledReport
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public function __construct(private readonly ReportsCatalogue $catalogue) {}

    public function handle(string $reportKey, string $frequency, ?int $dayOfWeek, ?int $dayOfMonth, User $actor): ScheduledReport
    {
        $definition = $this->catalogue->find($reportKey);

        if ($definition === null) {
            throw new InvalidArgumentException("No report '{$reportKey}' is in the catalogue.");
        }

        if (! $definition->schedulable) {
            throw new InvalidArgumentException("'{$definition->title}' is not one of the reports nominated for scheduling.");
        }

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException("'{$frequency}' is not a frequency; the column CHECK names the three that are.");
        }

        if ($frequency === 'weekly' && ($dayOfWeek === null || $dayOfWeek < 0 || $dayOfWeek > 6)) {
            throw new InvalidArgumentException('A weekly mail names the day it runs (0 = Sunday through 6 = Saturday).');
        }

        if ($frequency === 'monthly' && ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28)) {
            throw new InvalidArgumentException('A monthly mail names a day from 1 to 28 — the 29th of February is not a promise.');
        }

        if (! $definition->visibleToCurrentSeat()) {
            throw new InvalidArgumentException("This seat may not read '{$definition->title}', so it cannot schedule it.");
        }

        if (! Gate::allows($definition->exportAbility)) {
            throw new InvalidArgumentException("Scheduling a mail is a standing export; it needs '{$definition->exportAbility}'.");
        }

        $existing = ScheduledReport::query()->where('report', $reportKey)->first();

        return $existing?->update([
            'frequency' => $frequency,
            'day_of_week' => $frequency === 'weekly' ? $dayOfWeek : null,
            'day_of_month' => $frequency === 'monthly' ? $dayOfMonth : null,
            'enabled' => true,
            'set_by' => $actor->id,
        ]) === true
            ? $existing->fresh()
            : ScheduledReport::create([
                'id' => (string) Str::ulid(),
                'report' => $reportKey,
                'frequency' => $frequency,
                'day_of_week' => $frequency === 'weekly' ? $dayOfWeek : null,
                'day_of_month' => $frequency === 'monthly' ? $dayOfMonth : null,
                'enabled' => true,
                'set_by' => $actor->id,
            ]);
    }

    public function disable(string $reportKey): void
    {
        ScheduledReport::query()->where('report', $reportKey)->update(['enabled' => false]);
    }
}
