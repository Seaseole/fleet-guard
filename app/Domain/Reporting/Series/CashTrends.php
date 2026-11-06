<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * Cash in and cash out per bucket of time, for the diverging column trends card.
 *
 * The same discipline as {@see MonthlyRevenueAndExpense}: the ledger's door has no date dimension of its
 * own (rule D3), so the walk happens here, one `cashFlow` read per bucket, and the picture can never
 * disagree with the signed cash-flow sheet because it is the same sheet asked about narrower windows.
 *
 * Three periods share one reading order — the window's months, the last month's weeks, the last seven
 * days — so the card's Week / Month / Year control answers with the same door at three zoom levels.
 * A bucket with no movement is zero, not a gap: an absent column would claim the ledger was silent
 * rather than empty.
 */
final class CashTrends
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function __invoke(ChartWindow $window): CashTrendsPayload
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $months = $window->months();
        $year = array_map(
            fn (array $month): array => $this->bucket($month['from'], $month['to']),
            $months,
        );

        // The last month of the window, walked in weeks; the last seven days, walked in days.
        $lastMonth = $months !== [] ? $months[count($months) - 1] : null;
        $monthBuckets = [];

        if ($lastMonth !== null) {
            $cursor = $lastMonth['from'];
            $monthEnd = $lastMonth['to'];

            while ($cursor->lte($monthEnd)) {
                $end = $cursor->addDays(6)->lte($monthEnd) ? $cursor->addDays(6) : $monthEnd;
                $monthBuckets[] = $this->bucket($cursor, $end);
                $cursor = $end->addDay();
            }
        }

        $weekBuckets = [];
        $today = $window->asAt();

        for ($i = 6; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $weekBuckets[] = $this->bucket($day, $day);
        }

        $plotted = array_values(array_filter([
            ['value' => 'year', 'label' => __('Year'), 'data' => array_values($year)],
            ['value' => 'month', 'label' => __('Month'), 'data' => $monthBuckets],
            ['value' => 'week', 'label' => __('Week'), 'data' => $weekBuckets],
        ], static fn (array $p): bool => $p['data'] !== []));

        $tables = [];

        foreach ($plotted as $period) {
            $tables[$period['value']] = $this->table($period['value'], $period['data'], $currency, $window);
        }

        return new CashTrendsPayload(
            id: 'cash-trends',
            title: __('Cash in, cash out'),
            totalLabel: __('Total net cash flow'),
            inflowLabel: __('Cash in'),
            outflowLabel: __('Cash out'),
            ariaLabel: __('Column chart of cash in above and cash out below a zero line, from :from to :to.', [
                'from' => $window->from->format('M Y'),
                'to' => $window->to->format('M Y'),
            ]),
            windowLabel: $window->label(),
            windowKey: $window->key(),
            currency: $currency,
            periods: $plotted,
            tables: $tables,
        );
    }

    /**
     * @return array{date: string, end: string, in: int, out: int}
     */
    private function bucket(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sheet = $this->statements->cashFlow($from, $to);

        $in = 0;
        $out = 0;

        foreach ($sheet->sections as $section) {
            foreach ($section->lines as $line) {
                $in += $line->inflowMinor;
                $out += $line->outflowMinor;
            }
        }

        return [
            'date' => $from->toDateString(),
            'end' => $to->toDateString(),
            'in' => $in,
            'out' => $out,
        ];
    }

    /**
     * The rows that say what the columns say (the same promise `x-patterns.chart` makes with its
     * table): a screen reader, a printed page and a seat that prefers numbers all read this, from the
     * same payload the picture is drawn from. One table per period, because the card's Week / Month /
     * Year control re-plots the columns and the rows must follow it rather than freeze on the first.
     *
     * @param  list<array{date: string, end: string, in: int, out: int}>  $data
     * @return array{caption: string, columns: list<array{label: string, numeric: bool}>, rows: list<list<string>>, totals: list<string>}
     */
    private function table(string $period, array $data, string $currency, ChartWindow $window): array
    {
        $rows = [];
        $totals = ['in' => 0, 'out' => 0];

        foreach ($data as $bucket) {
            $rows[] = [
                $this->rowLabel($period, $bucket),
                Cells::minor($bucket['in'], $currency),
                Cells::minor($bucket['out'], $currency),
                Cells::minor($bucket['in'] - $bucket['out'], $currency),
            ];
            $totals['in'] += $bucket['in'];
            $totals['out'] += $bucket['out'];
        }

        return [
            'caption' => __('Cash in and out by :period, :window', [
                'period' => strtolower(__($this->columnLabel($period))),
                'window' => $window->label(),
            ]),
            'columns' => [
                ['label' => __($this->columnLabel($period)), 'numeric' => false],
                ['label' => __('Cash in'), 'numeric' => true],
                ['label' => __('Cash out'), 'numeric' => true],
                ['label' => __('Net'), 'numeric' => true],
            ],
            'rows' => $rows,
            'totals' => [
                __('Window'),
                Cells::minor($totals['in'], $currency),
                Cells::minor($totals['out'], $currency),
                Cells::minor($totals['in'] - $totals['out'], $currency),
            ],
        ];
    }

    private function columnLabel(string $period): string
    {
        return match ($period) {
            'month' => 'Week',
            'week' => 'Day',
            default => 'Month',
        };
    }

    /**
     * @param  array{date: string, end: string}  $bucket
     */
    private function rowLabel(string $period, array $bucket): string
    {
        $from = CarbonImmutable::parse($bucket['date']);
        $to = CarbonImmutable::parse($bucket['end']);

        if ($period === 'week') {
            return $from->translatedFormat('D j M');
        }

        if ($period === 'month') {
            return $from->toDateString() === $to->toDateString()
                ? $from->translatedFormat('j M')
                : __(':from to :to', ['from' => $from->translatedFormat('j M'), 'to' => $to->translatedFormat('j M')]);
        }

        return $from->translatedFormat('M Y');
    }
}
