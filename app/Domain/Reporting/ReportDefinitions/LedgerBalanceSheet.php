<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The balance sheet as report data, read through the same Accounting door the screen
 * uses so the export, the mail and the sheet cannot print three answers.
 */
final class LedgerBalanceSheet implements ReportBuilder
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $sheet = $this->statements->balanceSheet($to);

        $rows = [];

        foreach ($sheet->assetSections as $section) {
            foreach ($section->lines as $line) {
                $rows[] = [$section->heading, $line->code, $line->name, Cells::minor($line->amountMinor, $sheet->currencyCode, true)];
            }
        }

        foreach ($sheet->liabilitySections as $section) {
            foreach ($section->lines as $line) {
                $rows[] = [$section->heading, $line->code, $line->name, Cells::minor($line->amountMinor, $sheet->currencyCode, true)];
            }
        }

        foreach ($sheet->equitySections as $section) {
            foreach ($section->lines as $line) {
                $rows[] = [$section->heading, $line->code, $line->name, Cells::minor($line->amountMinor, $sheet->currencyCode, true)];
            }
        }

        return new ReportData(
            title: 'Balance sheet',
            window: 'as at '.$to->format('d M Y'),
            columns: [
                ['label' => 'Section', 'numeric' => false],
                ['label' => 'Account', 'numeric' => false],
                ['label' => 'Name', 'numeric' => false],
                ['label' => 'Amount', 'numeric' => true],
            ],
            rows: $rows,
            totals: [
                'Equation', '',
                'Assets '.Cells::minor($sheet->totalAssetsMinor(), $sheet->currencyCode).
                ' vs liabilities '.Cells::minor($sheet->totalLiabilitiesMinor(), $sheet->currencyCode).
                ' + equity '.Cells::minor($sheet->totalEquityMinor(), $sheet->currencyCode),
                Cells::minor($sheet->totalAssetsMinor(), $sheet->currencyCode),
            ],
            note: $sheet->isBalanced()
                ? 'Balanced: assets equal liabilities plus equity, open-period earnings included.'
                : 'OUT OF BALANCE by '.Cells::minor($sheet->differenceMinor(), $sheet->currencyCode).' — run ledger:verify before signing anything.',
        );
    }
}
