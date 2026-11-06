<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The P&L as report data, one row per moved account with its statement section named (the
 * financial statement, read through the same Accounting door the screen uses so the export,
 * the mail and the sheet cannot print three answers).
 */
final class LedgerProfitLoss implements ReportBuilder
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $sheet = $this->statements->profitAndLoss($from, $to);

        $rows = [];

        foreach ($sheet->revenueLines as $line) {
            $rows[] = ['Revenue', $line->code, $line->name, Cells::minor($line->amountMinor, $sheet->currencyCode)];
        }

        foreach ($sheet->expenseLines as $line) {
            $rows[] = ['Expense', $line->code, $line->name, Cells::minor($line->amountMinor, $sheet->currencyCode)];
        }

        return new ReportData(
            title: 'Profit and loss',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: [
                ['label' => 'Section', 'numeric' => false],
                ['label' => 'Account', 'numeric' => false],
                ['label' => 'Name', 'numeric' => false],
                ['label' => 'Amount', 'numeric' => true],
            ],
            rows: $rows,
            totals: [
                'Net', '',
                'Revenue '.Cells::minor($sheet->totalRevenueMinor(), $sheet->currencyCode).
                ' − Expenses '.Cells::minor($sheet->totalExpenseMinor(), $sheet->currencyCode),
                Cells::minor($sheet->netProfitMinor(), $sheet->currencyCode),
            ],
        );
    }
}
