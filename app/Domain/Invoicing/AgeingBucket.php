<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * §10.4 axis three — how late an invoice is, derived from `due_on` against today.
 *
 * Stored nowhere. An ageing column is a number that is wrong every midnight, and the AR report would then
 * be a stale figure the accountant discovers at month end. `Invoice::ageingState()` computes it, and the
 * buckets are the five §10.4 names plus `disputed`, which is not a date question at all.
 *
 * `disputed` is unreachable from a date and is deliberately not inferred from a credit note existing: a
 * credit note is a correction that has been agreed, whereas a dispute is a customer who has not paid and
 * has said why. Only an explicit flag can raise it, which is why the derivation below never returns it.
 */
enum AgeingBucket: string
{
    case Current = 'current';
    case Days1to30 = 'd1_30';
    case Days31to60 = 'd31_60';
    case Days61to90 = 'd61_90';
    case Over90 = 'd90_plus';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Not yet due',
            self::Days1to30 => '1–30 days',
            self::Days31to60 => '31–60 days',
            self::Days61to90 => '61–90 days',
            self::Over90 => 'Over 90 days',
            self::Disputed => 'Disputed',
        };
    }

    /**
     * The buckets that make an invoice "overdue" on a dashboard, oldest-first order.
     *
     * @return list<self>
     */
    public static function overdue(): array
    {
        return [self::Over90, self::Days61to90, self::Days31to60, self::Days1to30];
    }

    /**
     * The bucket a due date falls into today. The boundary days belong to the older bucket, so a 30-day
     * invoice is `1–30` and a 31-day one is `31–60` — the same reading as the §10.4 names.
     */
    public static function for(DateTimeInterface $dueOn, ?DateTimeInterface $today = null): self
    {
        // The application boots with `Date::use(CarbonImmutable::class)`, so an invoice's cast date is not
        // an `Illuminate\Support\Carbon`. Normalising here is the same boundary `Fx` normalises at.
        $due = Carbon::instance($dueOn)->startOfDay();
        $today = $today === null ? Carbon::today() : Carbon::instance($today)->startOfDay();
        $days = $today->diffInDays($due, false);

        return match (true) {
            $days >= 0 => self::Current,
            $days >= -30 => self::Days1to30,
            $days >= -60 => self::Days31to60,
            $days >= -90 => self::Days61to90,
            default => self::Over90,
        };
    }
}
