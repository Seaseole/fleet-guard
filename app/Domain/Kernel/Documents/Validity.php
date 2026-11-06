<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The derived axis. There is deliberately no `validity` column.
 *
 * The design doc's reason is the one that matters here: a licence, a permit, an insurance policy
 * and a weighbridge ticket all need "is this still good?", and storing that answer per row means
 * four schedulers writing four definitions of `expired` into four tables. Deriving it from
 * `expires_on` in exactly this method is what makes the answer the same everywhere — and what makes
 * the nightly job an emitter of alerts rather than an editor of truth.
 *
 * `expires_on` is a *date*, evaluated against a *business day* in the company's own timezone (
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
     * comparison at all — and it is the rule this enum's own docblock states.
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
        return self::on($expiresOn, Carbon::now(CurrentCompany::timezone()));
    }

    /**
     * The company's window, anchored on a day the caller names instead of today.
     *
     * A driver picker does not ask "is this paper good today", it asks "is it good on the day the work
     * starts" — and it is the same window with a different business day. Without this door each
     * screen would read `documents.expiry_warning_days` itself, which is how one knob becomes four.
     */
    public static function on(?CarbonInterface $expiresOn, CarbonInterface $day): self
    {
        /** @var int $warningDays */
        $warningDays = (int) app(CompanySettings::class)->get('documents.expiry_warning_days');

        return self::derive($expiresOn, $day, $warningDays);
    }

    /**
     * The worse state of a subject that holds several papers — what a row edge or an option prints.
     *
     * Ordered by what a reader has to do, not by severity of the dates: a lapsed paper is an action
     * stopped, one inside the window is a renewal in progress, and a paper with no date is a gap in the
     * file rather than a clean bill of health, so it sits above `Valid` and below the two real states.
     *
     * @param  array<self>  $states
     */
    public static function worstOf(array $states): self
    {
        $rank = [self::Valid->value => 0, self::Perpetual->value => 1, self::ExpiringSoon->value => 2, self::Expired->value => 3];
        $worst = self::Valid;

        foreach ($states as $state) {
            if ($rank[$state->value] > $rank[$worst->value]) {
                $worst = $state;
            }
        }

        return $worst;
    }

    /**
     * The distance in words, which the state word alone does not give: "in 14 days", "3 days ago".
     *
     * Phrased here because every screen that shows a band shows this figure beside it, and a register
     * that says "in 14 days" while the picker says "14d" is two features arguing on one page.
     */
    public static function distance(?CarbonInterface $expiresOn, CarbonInterface $day): string
    {
        $days = self::daysUntil($expiresOn, $day);

        return match (true) {
            $days === null => '',
            $days === 0 => 'expires today',
            $days > 0 => 'in '.$days.' days',
            default => abs($days).' days ago',
        };
    }

    /**
     * The papers of one subject that are not clean on a given day — the shape every picker prints.
     *
     * Three screens choose a person by their papers (the truck's assignment card, the dispatch board's
     * plan, the inspection attribution), and each of them would otherwise write this loop, and each loop
     * would eventually disagree with the register that shows the same bands.
     *
     * A paper with no date is **danger** here although the people register keeps it neutral: on a register
     * a blank date is a gap in a file, and on a list that commits work it is the refusal the door will
     * raise. The screen chooses which of those it is by where it asks.
     *
     * @param  list<array{label: string, date: ?CarbonInterface}>  $papers
     * @return list<array{paper: string, tone: string, state: string, days: string, on: string}>
     */
    public static function paperFlags(array $papers, CarbonInterface $day): array
    {
        $flags = [];

        foreach ($papers as $paper) {
            $state = self::on($paper['date'], $day);

            if ($state === self::Valid) {
                continue;
            }

            $flags[] = [
                'paper' => $paper['label'],
                'tone' => $state === self::ExpiringSoon ? 'warning' : 'danger',
                'state' => $state === self::Perpetual ? 'no date on file' : $state->label(),
                'days' => self::distance($paper['date'], $day),
                'on' => $paper['date']?->format('d M Y') ?? '',
            ];
        }

        return $flags;
    }

    /**
     * The worse tone of a set of flags — the colour an option or a row carries.
     *
     * @param  list<array<string, string>>  $flags
     */
    public static function optionTone(array $flags): string
    {
        $tones = array_column($flags, 'tone');

        return match (true) {
            in_array('danger', $tones, true) => 'danger',
            in_array('warning', $tones, true) => 'warning',
            default => '',
        };
    }

    /**
     * The reason a list prints beside a name, so the colour is never the only channel.
     *
     * @param  list<array<string, string>>  $flags
     */
    public static function optionNote(array $flags): string
    {
        return implode(', ', array_map(
            static fn (array $flag): string => $flag['paper'].' '.$flag['state']
                .($flag['on'] === '' ? '' : ' ('.$flag['on'].')')
                .($flag['days'] === '' ? '' : ', '.$flag['days']),
            $flags,
        ));
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

    /** The `ui/badge` tone for this state; the caller still supplies the icon. */
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
