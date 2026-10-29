<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Resolves `period_key` from the scope's restart policy (§7.4's ⚠ note: the policy is a setting,
 * not a migration, because §17 Q2/Q3 are still open with the accountant).
 *
 *   continuous     → ''          one unbroken run, used for trips, jobs, work orders
 *   calendar_year  → '2026'
 *   financial_year → 'FY26/27'   from the company's fiscal_year_starts_on
 *
 * Dates are accepted as DateTimeInterface and normalised with Carbon::instance(): the app boots
 * with `Date::use(CarbonImmutable::class)` (AppServiceProvider), so `now()` hands back a
 * CarbonImmutable, which is NOT a subclass of Carbon — type-hinting Carbon here would reject the
 * application's own clock.
 */
final class NumberPeriod
{
    public const Continuous = 'continuous';

    public const CalendarYear = 'calendar_year';

    public const FinancialYear = 'financial_year';

    /**
     * @param  string  $scope  only used to name the scope in the error when the policy is invalid
     */
    public static function keyFor(string $restart, DateTimeInterface|CarbonImmutable $on, string $scope = ''): string
    {
        $on = Carbon::instance($on);

        return match ($restart) {
            self::Continuous => '',
            self::CalendarYear => $on->format('Y'),
            self::FinancialYear => self::financialYearLabel($on),
            default => throw NumberingError::unknownRestartPolicy($scope, $restart),
        };
    }

    /**
     * The FY a date falls in, from the company's own year-start rather than the calendar's.
     * A 1 January fiscal year still gets the FY26/27 label for consistency of the sequence key.
     */
    public static function financialYearOf(DateTimeInterface|CarbonImmutable $on): int
    {
        $on = Carbon::instance($on);
        [$startMonth, $startDay] = self::fiscalYearStart();

        $candidate = Carbon::create($on->year, $startMonth, $startDay, 0, 0, 0, $on->timezone);

        // Before this year's anniversary, the date belongs to the year that already started.
        return $on->lessThan($candidate) ? $on->year - 1 : $on->year;
    }

    public static function financialYearLabel(DateTimeInterface|CarbonImmutable $on): string
    {
        $start = self::financialYearOf($on);

        return sprintf('FY%02d/%02d', $start % 100, ($start + 1) % 100);
    }

    /** @return array{int, int} [month, day] */
    public static function fiscalYearStart(): array
    {
        // NOT NULL with a migration default, so there is no "company hasn't chosen yet" state
        // to handle — the default IS the choice until the accountant changes it (§17 Q3).
        $startsOn = CurrentCompany::model()->fiscal_year_starts_on;

        return [(int) $startsOn->format('n'), (int) $startsOn->format('j')];
    }
}
