<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The cash-flow statement as report data (the window statement), one row per counterparty
 * account with its in, out and net — the same figures the accounting screen prints, through
 * the same door.
 */
final class LedgerCashFlow implements ReportBuilder
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $sheet = $this->statements->cashFlow($from, $to);

        $rows = [];

        foreach ($sheet->sections as $section) {
            foreach ($section->lines as $line) {
                $rows[] = [
                    $section->heading,
                    $line->code,
                    $line->name,
                    Cells::minor($line->inflowMinor, $sheet->currencyCode, true),
                    Cells::minor($line->outflowMinor, $sheet->currencyCode, true),
                    Cells::minor($line->netMinor(), $sheet->currencyCode, true),
                ];
            }

            $rows[] = [
                $section->heading.' net', '', '',
                Cells::minor($section->inflowMinor(), $sheet->currencyCode),
                Cells::minor($section->outflowMinor(), $sheet->currencyCode),
                Cells::minor($section->netMinor(), $sheet->currencyCode),
            ];
        }

        return new ReportData(
            title: 'Cash flow',
            window: $from->format('d M Y').' → '.$to->format('d M Y'),
            columns: [
                ['label' => 'Section', 'numeric' => false],
                ['label' => 'Account', 'numeric' => false],
                ['label' => 'Name', 'numeric' => false],
                ['label' => 'In', 'numeric' => true],
                ['label' => 'Out', 'numeric' => true],
                ['label' => 'Net', 'numeric' => true],
            ],
            rows: $rows,
            totals: [
                'Cash', '',
                'Opening '.Cells::minor($sheet->openingCashMinor, $sheet->currencyCode).
                ' + net '.Cells::minor($sheet->netChangeMinor(), $sheet->currencyCode),
                '', '',
                Cells::minor($sheet->closingCashMinor, $sheet->currencyCode),
            ],
            note: $sheet->isConsistent()
                ? 'Ties out: opening cash plus the section nets equal closing cash, each half read through its own ledger door.'
                : 'DOES NOT TIE OUT — opening plus section nets disagrees with closing cash; run ledger:verify.',
        );
    }
}
