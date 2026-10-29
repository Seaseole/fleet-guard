<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * §10.8's derived axis. There is deliberately no `validity` column.
 *
 * The design doc's reason is the one that matters here: a licence, a permit, an insurance policy
 * and a weighbridge ticket all need "is this still good?", and storing that answer per row means
 * four schedulers writing four definitions of `expired` into four tables. Deriving it from
 * `expires_on` in exactly this method is what makes the answer the same everywhere — and what makes
 * the nightly job an emitter of alerts rather than an editor of truth.
 *
 * `expires_on` is a *date*, evaluated against a *business day* in the company's own timezone (§2:
 * Africa/Gaborone for display, UTC for storage). That distinction is not cosmetic: a licence that
 * expires on 31 October at midnight Gaborone would read as expired from 22:00 UTC on 30 October if
 * the comparison used the storage zone, and the owner would see a truck stood down a day early.
 */
enum Validity: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Perpetual = 'perpetual';

    public static function derive(?CarbonInterface $expiresOn, CarbonInterface $today, int $warningDays): self
    {
        if ($expiresOn === null) {
            return self::Perpetual;
        }

        $startOfToday = $today->copy()->startOfDay();
        $expiry = self::midnightOn($expiresOn, $startOfToday);

        if ($expiry->lt($startOfToday)) {
            return self::Expired;
        }

        // Inclusive of the warning boundary: a document expiring exactly `warningDays` away is
        // already inside the window in which the renewal has to be in progress, and reporting it
        // as valid is the difference between a reminder and a fine.
        if ($expiry->lte($startOfToday->copy()->addDays($warningDays))) {
            return self::ExpiringSoon;
        }

        return self::Valid;
    }

    /**
     * Whole calendar days from the business day to the expiry, negative once it has lapsed.
     *
     * Shares `derive()`'s re-anchoring because a `days_until_expiry` that disagrees with the state
     * printed beside it in the same payload is a row nobody can read.
     */
    public static function daysUntil(?CarbonInterface $expiresOn, CarbonInterface $businessDay): ?int
    {
        if ($expiresOn === null) {
            return null;
        }

        $today = $businessDay->copy()->startOfDay();

        /** @var float $difference */
        $difference = $today->diffInDays(self::midnightOn($expiresOn, $today), false);

        return (int) $difference;
    }

    /**
     * The stored date as midnight **in the business day's own zone**.
     *
     * `expires_on` is a `date` column, so the cast hands back midnight in the storage zone (UTC here)
     * while `$businessDay` is midnight in the company zone (Africa/Gaborone, +2). Comparing those two
     * instants shifts every boundary by the offset — measured on this database, a paper exactly sixty
     * days out derived `valid`, because "2026-12-26 00:00 UTC" is two hours *after* "sixty days from
     * today" when the latter means Gaborone midnight. So each band fired two hours late, and for a
     * company west of UTC the same arithmetic calls a paper that expires today `expired` this morning.
     *
     * Re-anchoring the calendar date, rather than converting the instant, is what makes this a date
     * comparison at all — and it is the §2 rule this enum's own docblock states.
     */
    private static function midnightOn(CarbonInterface $expiresOn, CarbonInterface $businessDay): Carbon
    {
        /** @var Carbon $anchored */
        $anchored = Carbon::parse($expiresOn->toDateString(), $businessDay->getTimezone())->startOfDay();

        return $anchored;
    }

    /**
     * The one call site most screens want: derive against the company's business day today.
     *
     * Kept separate from `derive()` so the pure function above stays free of tenancy and config,
     * which is what lets `documents:expiry-scan` pass an explicit date and re-run a past night.
     */
    public static function for(?CarbonInterface $expiresOn): self
    {
        /** @var int $warningDays */
        $warningDays = (int) config('fleet.documents.expiry_warning_days', 60);

        return self::derive($expiresOn, Carbon::now(CurrentCompany::timezone()), $warningDays);
    }

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::ExpiringSoon => 'Expiring soon',
            self::Expired => 'Expired',
            self::Perpetual => 'No expiry',
        };
    }

    /** The `ui/badge` tone for this state; the caller still supplies the icon (DESIGN-SYSTEM.md §1.5). */
    public function tone(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'danger',
            self::Perpetual => 'neutral',
        };
    }
}
