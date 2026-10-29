<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Carbon\CarbonInterface;

/**
 * Where a rate card stands relative to a business date.
 *
 * Derived, never stored: the same row reads `scheduled` before its window, `effective` inside it
 * and `expired` after. Storing it would need a nightly job to keep a word in sync with two dates,
 * and the first night that job misses would price a trip off a card the screen calls current.
 */
enum RouteRateState: string
{
    case Scheduled = 'scheduled';
    case Effective = 'effective';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Effective => 'Effective',
            self::Expired => 'Expired',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'warning',
            self::Effective => 'success',
            self::Expired => 'neutral',
        };
    }

    /**
     * The windows come from `date` columns (UTC midnight) and `$on` is the company's business day
     * (`Carbon::today('Africa/Gaborone')` — 22:00 UTC the evening before). Comparing *instants*
     * would call a card starting today "scheduled" for two hours of every day; comparing the
     * calendar dates is what the column means (§2).
     */
    public static function of(CarbonInterface $from, ?CarbonInterface $to, CarbonInterface $on): self
    {
        $day = $on->toDateString();

        if ($from->toDateString() > $day) {
            return self::Scheduled;
        }

        if ($to !== null && $to->toDateString() < $day) {
            return self::Expired;
        }

        return self::Effective;
    }
}
