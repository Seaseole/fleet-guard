<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Formatter;
use App\Domain\Reporting\Support\Cells;

/**
 * What the book is made of at one date — the three sides the balance sheet already totals, read as a
 * proportion bar rather than re-derived (docs/REDESIGN-CONCEPT.md §4, the reading room).
 *
 * The door asks {@see LedgerStatements::balanceSheet()} once and takes the three section totals the
 * signed sheet prints below it. That is the whole identity made visible: because assets are funded by
 * liabilities plus equity, the bar always splits in half, and a seat who sees the halves is seeing the
 * balance prove itself — no fourth segment and no recomputed share could say anything the sheet does
 * not already print.
 */
final class BalanceSheetComposition
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function __invoke(ChartWindow $window): Series
    {
        $sheet = $this->statements->balanceSheet($window->asAt());
        $currency = $sheet->currencyCode;

        $sides = [
            ['label' => __('Assets held'), 'minor' => $sheet->totalAssetsMinor(), 'colour' => '--color-accent'],
            ['label' => __('Owed (liabilities)'), 'minor' => $sheet->totalLiabilitiesMinor(), 'colour' => '--color-fg-ink-secondary'],
            ['label' => __('Funded (equity)'), 'minor' => $sheet->totalEquityMinor(), 'colour' => '--color-success'],
        ];

        $whole = array_sum(array_column($sides, 'minor'));

        $labels = [];
        $data = [];
        $shares = [];
        $amounts = [];
        $colours = [];
        $rows = [];

        foreach ($sides as $side) {
            $labels[] = $side['label'];
            $data[] = $side['minor'];
            $shares[] = Formatter::number($whole > 0 ? $side['minor'] / $whole * 100 : 0, 0).'%';
            $amounts[] = Cells::minor($side['minor'], $currency, dashZero: true);
            $colours[] = $side['colour'];

            $rows[] = [$side['label'], Cells::minor($side['minor'], $currency), $shares[count($shares) - 1]];
        }

        return new Series(
            id: 'balance-sheet-composition',
            type: 'proportion',
            title: __('What the book is made of'),
            ariaLabel: __('Stacked bar of the balance sheet as at :date: assets :assets, liabilities :liabilities, equity :equity.', [
                'date' => $window->asAt()->format('d M Y'),
                'assets' => Cells::minor($sheet->totalAssetsMinor(), $currency),
                'liabilities' => Cells::minor($sheet->totalLiabilitiesMinor(), $currency),
                'equity' => Cells::minor($sheet->totalEquityMinor(), $currency),
            ]),
            windowLabel: __('As at :date', ['date' => $window->asAt()->format('d M Y')]),
            windowKey: $window->key(),
            labels: $labels,
            datasets: [[
                'label' => __('Balance'),
                'data' => $data,
                'formatted' => $shares,
                'amounts' => $amounts,
                'colour' => $colours,
                'pattern' => 'solid',
            ]],
            axes: [],
            table: [
                'caption' => __('Balance sheet sides as at :date', ['date' => $window->asAt()->format('d M Y')]),
                'columns' => [
                    ['label' => __('Side'), 'numeric' => false],
                    ['label' => __('Balance'), 'numeric' => true],
                    ['label' => __('Share'), 'numeric' => true],
                ],
                'rows' => $rows,
                'totals' => [
                    __('The book'),
                    Cells::minor($whole, $currency),
                    $whole > 0 ? '100%' : '0%',
                ],
            ],
            note: __('Assets are funded by liabilities plus equity, so the halves are the identity: the bar splits evenly because the sheet balances.'),
        );
    }
}
