<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Exporters\ReportFileWriter;
use App\Domain\Reporting\Mail\ScheduledReportMail;
use App\Domain\Reporting\Models\ScheduledReport;
use App\Domain\Reporting\ReportExportFormat;
use App\Domain\Reporting\ReportsCatalogue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * One due nomination, executed as a worker (again: the payload is two scalars and the
 * tenant is pinned inside).
 *
 * Recipients are resolved at send time from the export ability, not from a stored list — a
 * revoked role stops the mail on the next run without anyone remembering to edit a recipient
 * field (the scheduled send is an export made by the calendar on the seat's behalf).
 */
final class SendScheduledReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const PREVIEW_ROWS = 30;

    public function __construct(
        private readonly string $scheduledId,
        private readonly int $companyId,
    ) {}

    public function handle(ReportsCatalogue $catalogue, ReportFileWriter $writer): void
    {
        CurrentCompany::force($this->companyId);

        try {
            $scheduled = ScheduledReport::query()->find($this->scheduledId);

            if ($scheduled === null || ! $scheduled->enabled) {
                return;
            }

            $definition = $catalogue->find($scheduled->report);

            if ($definition === null) {
                return;
            }

            $today = Carbon::now(CurrentCompany::timezone());
            [$from, $to] = $scheduled->windowFor($today);

            // windowFor answers with mutable dates (the company clock); the builders speak
            // CarbonImmutable, the same currency the screen hands them (one shape).
            $data = app($definition->builder)->build(
                CarbonImmutable::instance($from),
                CarbonImmutable::instance($to),
            );

            $recipients = User::query()
                ->where('company_id', $this->companyId)
                ->whereNotNull('email')
                ->whereNotNull('email_verified_at')
                ->get()
                ->filter(fn (User $user): bool => $user->can($definition->exportAbility))
                ->pluck('email')
                ->all();

            if ($recipients === []) {
                // Nobody holds the ability today. The nomination stands; the mail simply has
                // no authorised reader. Saying so loudly would be the alarm job's work.
                return;
            }

            $file = $writer->write($data, ReportExportFormat::Pdf);

            Mail::to($recipients)->queue(
                new ScheduledReportMail(
                    reportTitle: __($definition->title),
                    window: $data->window,
                    companyName: CurrentCompany::model()->name,
                    tableLines: $this->preview($data),
                    note: $data->note,
                    pdf: $file['bytes'],
                    filename: $scheduled->report.'_'.Carbon::instance($from)->toDateString().'_'.Carbon::instance($to)->toDateString().'.pdf',
                    truncatedBy: count($data->rows) > self::PREVIEW_ROWS
                        ? 'Showing the first '.self::PREVIEW_ROWS.' of '.count($data->rows).' rows inline; the PDF carries the sheet whole.'
                        : '',
                )->onQueue('reports'),
            );

            $scheduled->update(['last_sent_at' => Carbon::now()]);
        } finally {
            CurrentCompany::forget();
        }
    }

    /** @return list<string> */
    private function preview(ReportData $data): array
    {
        $lines = [implode(' | ', array_map(
            static fn (array $column): string => $column['label'],
            $data->columns,
        ))];

        foreach (array_slice($data->rows, 0, self::PREVIEW_ROWS) as $row) {
            $lines[] = implode(' | ', $row);
        }

        if ($data->totals !== null) {
            $lines[] = implode(' | ', $data->totals);
        }

        return $lines;
    }
}
