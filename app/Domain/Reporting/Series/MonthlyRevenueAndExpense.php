<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Support\Cells;

/**
 * Earned against spent, one point per month.
 *
 * This is the chart the ledger's rules are most directly about. DESIGN.md the design is explicit that a list view or a
 * dashboard never reads the journal's line table for itself, and rule D3 keeps it that way, so the trend is not
 * allowed its own grouped query — it asks {@see LedgerStatements::profitAndLoss()} once per month, which is the
 * same door the profit-and-loss page and the two money cards above already use. The consequence is that the
 * picture and the signed sheet cannot disagree: if they ever do, one of them has a bug (the rule, not a
 * different definition).
 *
 * The month walk happens in PHP for two independent reasons: the ledger door carries no date dimension of its own,
 * and the dev database is MySQL while the suite runs on sqlite, so a month grouped in SQL would be a chart that
 * passes locally and fails elsewhere.
 *
 * A month with no postings is a zero, not a gap. A missing point on a line reads as "we did not earn in August",
 * which is a claim about the data rather than about the ledger's silence.
 */
final class MonthlyRevenueAndExpense
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function __invoke(ChartWindow $window): Series
    {
        $currency = CurrentCompany::model()->base_currency_code;

        $labels = [];
        $revenue = [];
        $expense = [];
        $rows = [];
        $totals = ['revenue' => 0, 'expense' => 0];

        foreach ($window->months() as $month) {
            $sheet = $this->statements->profitAndLoss($month['from'], $month['to']);

            $earned = $sheet->totalRevenueMinor();
            $spent = $sheet->totalExpenseMinor();
            $net = $earned - $spent;

            $labels[] = $month['label'];
            $revenue[] = $earned;
            $expense[] = $spent;
            $totals['revenue'] += $earned;
            $totals['expense'] += $spent;

            $rows[] = [
                $month['label'],
                Cells::minor($earned, $currency),
                Cells::minor($spent, $currency),
                Cells::minor($net, $currency),
            ];
        }

        return new Series(
            id: 'revenue-expense-trend',
            type: 'line',
            title: __('Earned against spent'),
            ariaLabel: __('Line chart of revenue and expenses per month from :from to :to.', [
                'from' => $window->from->format('M Y'),
                'to' => $window->to->format('M Y'),
            ]),
            windowLabel: $window->label(),
            windowKey: $window->key(),
            labels: $labels,
            datasets: [
                [
                    'label' => __('Earned'),
                    'data' => $revenue,
                    'formatted' => array_map(static fn (int $minor): string => Cells::minor($minor, $currency), $revenue),
                    // Teal is the app's one accent per view, so revenue keeps it and
                    // expense is drawn in the secondary ink with a dash — style before hue
                    //.
                    'colour' => '--color-accent',
                    'pattern' => 'solid',
                    'axis' => 'y',
                ],
                [
                    'label' => __('Spent'),
                    'data' => $expense,
                    'formatted' => array_map(static fn (int $minor): string => Cells::minor($minor, $currency), $expense),
                    'colour' => '--color-fg-ink-secondary',
                    'pattern' => 'dash',
                    'axis' => 'y',
                ],
            ],
            axes: [Axis::money('y', $currency, label: __('In :code', ['code' => $currency]))],
            table: [
                'caption' => __('Revenue and expense by month, :window', ['window' => $window->label()]),
                'columns' => [
                    ['label' => __('Month'), 'numeric' => false],
                    ['label' => __('Earned'), 'numeric' => true],
                    ['label' => __('Spent'), 'numeric' => true],
                    ['label' => __('Net'), 'numeric' => true],
                ],
                'rows' => $rows,
                'totals' => [
                    __('Window'),
                    Cells::minor($totals['revenue'], $currency),
                    Cells::minor($totals['expense'], $currency),
                    Cells::minor($totals['revenue'] - $totals['expense'], $currency),
                ],
            ],
            note: __('Every point is the same profit-and-loss door the signed sheet reads, asked once per month.'),
        );
    }
}
