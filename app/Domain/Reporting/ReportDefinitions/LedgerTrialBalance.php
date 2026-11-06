<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Accounting\Data\TrialBalanceLine;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The trial balance as report data (the statement is the ledger's, this only shapes its
 * rows for a screen, an export or a mail). Reading through {@see LedgerStatements} keeps the
 * report and the accounting screen on one door — the pair cannot disagree by construction.
 */
final class LedgerTrialBalance implements ReportBuilder
{
    public function __construct(private readonly LedgerStatements $statements) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $balance = $this->statements->trialBalance($to);

        return new ReportData(
            title: 'Trial balance',
            window: 'as at '.$to->format('d M Y'),
            columns: [
                ['label' => 'Account', 'numeric' => false],
                ['label' => 'Name', 'numeric' => false],
                ['label' => 'Debit', 'numeric' => true],
                ['label' => 'Credit', 'numeric' => true],
            ],
            rows: array_map(
                static fn (TrialBalanceLine $line): array => [
                    $line->code,
                    $line->name,
                    Cells::minor($line->debitMinor, $balance->currencyCode, true),
                    Cells::minor($line->creditMinor, $balance->currencyCode, true),
                ],
                $balance->lines,
            ),
            totals: [
                'Totals', '',
                Cells::minor($balance->totalDebitMinor(), $balance->currencyCode),
                Cells::minor($balance->totalCreditMinor(), $balance->currencyCode),
            ],
            note: $balance->isBalanced()
                ? 'Balanced: every entry passed the door that balances it.'
                : 'OUT OF BALANCE — run ledger:verify before reading any figure here.',
        );
    }
}
