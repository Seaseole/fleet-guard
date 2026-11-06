<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ReportDefinitions;

use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\PaymentStatus;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Support\Cells;
use Carbon\CarbonImmutable;

/**
 * The AR ageing the Phase 4d door already answers, plus what was actually collected in the
 * window. The rows come from {@see AgeingReport} itself — the report never re-derives a
 * bucket, because a stored or re-computed bucket is wrong every midnight and a second
 * implementation would let the register and the report disagree by design.
 */
final class ReceivablesAgeing implements ReportBuilder
{
    public function __construct(private readonly AgeingReport $ageing) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportData
    {
        $currency = CurrentCompany::model()->base_currency_code;
        $rows = [];

        $totals = ['current' => 0, 'd1' => 0, 'd31' => 0, 'd61' => 0, 'd90' => 0, 'all' => 0];

        foreach (($this->ageing)($to->toDateTimeImmutable()) as $row) {
            $rows[] = [
                $row->customerName,
                (string) $row->invoiceCount,
                Cells::minor($row->currentMinor, $currency, true),
                Cells::minor($row->days1to30Minor, $currency, true),
                Cells::minor($row->days31to60Minor, $currency, true),
                Cells::minor($row->days61to90Minor, $currency, true),
                Cells::minor($row->over90Minor, $currency, true),
                Cells::minor($row->totalMinor(), $currency, true),
                $row->oldestDueOn?->format('d M Y') ?? '—',
            ];

            $totals['current'] += $row->currentMinor;
            $totals['d1'] += $row->days1to30Minor;
            $totals['d31'] += $row->days31to60Minor;
            $totals['d61'] += $row->days61to90Minor;
            $totals['d90'] += $row->over90Minor;
            $totals['all'] += $row->totalMinor();
        }

        $collected = (int) Payment::query()
            ->where('status', PaymentStatus::Recorded->value)
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->sum('base_amount');

        return new ReportData(
            title: 'Receivables ageing and collections',
            window: 'ageing as at '.$to->format('d M Y').' · collected '.$from->format('d M Y').' → '.$to->format('d M Y'),
            columns: [
                ['label' => 'Customer', 'numeric' => false],
                ['label' => 'Invoices', 'numeric' => true],
                ['label' => 'Current', 'numeric' => true],
                ['label' => '1–30', 'numeric' => true],
                ['label' => '31–60', 'numeric' => true],
                ['label' => '61–90', 'numeric' => true],
                ['label' => '90+', 'numeric' => true],
                ['label' => 'Owed', 'numeric' => true],
                ['label' => 'Oldest due', 'numeric' => false],
            ],
            rows: $rows,
            totals: [
                'Total', '',
                Cells::minor($totals['current'], $currency, true),
                Cells::minor($totals['d1'], $currency, true),
                Cells::minor($totals['d31'], $currency, true),
                Cells::minor($totals['d61'], $currency, true),
                Cells::minor($totals['d90'], $currency, true),
                Cells::minor($totals['all'], $currency, true),
                '',
            ],
            note: 'Collected in the window: '.Cells::minor($collected, $currency).
                ' — received payments (recorded, not voided) by their money date.',
        );
    }
}
