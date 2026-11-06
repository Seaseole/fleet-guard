<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The window a chart answers, as one value object rather than two loose strings.
 *
 * The same window names the bars, the table, the card's caption and the `wire:key`, which is the only reason
 * those four can never end up describing different months. A seat gets it from the URL, so a chart is a link
 * worth sending to the accountant — and so a typed `?from=abc` must fall back rather than 500 the dashboard
 * (the report library parses unguarded at resources/views/pages/reporting/⚡index.blade.php:72 and does exactly
 * that; this is the corrected shape, not a copy of it).
 *
 * Month buckets are walked in PHP, never with `DATE_FORMAT` or a `groupByRaw`: the ledger's own door has no date
 * dimension and rule D3 keeps it that way, and the dev database is MySQL while the suite runs on sqlite.
 */
final readonly class ChartWindow
{
    /** The trailing span a chart shows when nobody asked for one: six months reads a trend without a scroll. */
    public const MONTHS = 6;

    /**
     * A window is a URL parameter, and a URL parameter is a request to do work. Six months is the answer; a year
     * is a generous stretch of it; anything larger gets clipped rather than run, or `?from=2000-01-01` would
     * quietly ask the ledger for a few thousand aggregate reads.
     */
    public const MAX_MONTHS = 24;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * @param  string|null  $from  raw query-string value; blank or unparseable means "no preference"
     * @param  string|null  $to  raw query-string value
     */
    public static function fromRequest(?string $from, ?string $to, CarbonImmutable $today): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null && $end === null) {
            return self::trailing($today);
        }

        $end ??= $today;
        $start ??= $end->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);

        // Either order is typable and either is a question; answering it is kinder than refusing it.
        return $start->lte($end) ? new self($start, $end) : new self($end, $start);
    }

    /** The default: the current month and the five before it, in the company's own today. */
    public static function trailing(CarbonImmutable $today): self
    {
        return new self($today->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1), $today);
    }

    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The months the window touches, each clamped to the window itself so a partial first and last month are
     * partial on the chart and in the table alike.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, label: string}>
     */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->startOfMonth();

        while ($cursor->lte($this->to) && count($months) < self::MAX_MONTHS) {
            $end = $cursor->endOfMonth();

            $months[] = [
                'from' => $cursor->lt($this->from) ? $this->from : $cursor,
                'to' => $end->gt($this->to) ? $this->to : $end,
                'label' => $cursor->format('M Y'),
            ];

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $months;
    }

    /** The day a point-in-time door reads — ageing as at this day, fleet as at this day. */
    public function asAt(): CarbonImmutable
    {
        return $this->to;
    }

    public function label(): string
    {
        return $this->from->format('d M Y').' → '.$this->to->format('d M Y');
    }

    /** Feeds `wire:key`, so a new window replaces the node instead of morphing a stale canvas in place. */
    public function key(): string
    {
        return $this->from->toDateString().'|'.$this->to->toDateString();
    }
}
