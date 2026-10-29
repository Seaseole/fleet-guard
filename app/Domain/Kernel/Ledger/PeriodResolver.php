<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger;

use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a business date into its accounting period row (§6.5).
 *
 * `firstOrCreate` *inside* the posting transaction, which is what the design says a missing
 * period must not do: it must not block the posting. Refusing to post because someone forgot to
 * run a period-creation command is how a ledger ends up with a hole in it.
 *
 * The GL period is the calendar month; the fiscal year only decides document-number periods
 * (ADR-0002), so this class deliberately does not read `companies.fiscal_year_starts_on`.
 */
final class PeriodResolver
{
    public function for(DateTimeInterface|CarbonImmutable $on): Period
    {
        $date = Carbon::instance($on);
        $month = Carbon::instance($date)->startOfMonth();

        /** @var Period $period */
        $period = Period::query()->firstOrCreate(
            [
                'company_id' => CurrentCompany::id(),
                'period' => $month->format('Y-m'),
            ],
            [
                'starts_on' => $month->toDateString(),
                'ends_on' => $month->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
            ],
        );

        return $period;
    }
}
