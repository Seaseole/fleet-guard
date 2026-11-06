<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Exporters\ReportFileWriter;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportExportStatus;
use App\Domain\Reporting\ReportsCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one queued export (; The rule: the worker gets scalars and pins its own
 * tenant, because a queued payload holding models or money objects would deserialize blind).
 *
 * The window is read off the row, not the request: the file must answer exactly the question
 * the seat asked on the day it asked it, and the row already says which that is. Failures land
 * on the row with their reason and on the queue-health screen — they do not throw the job into
 * a retry loop that writes the same partial file twice.
 */
final class RunReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $exportId,
        private readonly int $companyId,
    ) {}

    public function handle(ReportsCatalogue $catalogue, ReportFileWriter $writer): void
    {
        CurrentCompany::force($this->companyId);

        try {
            $export = ReportExport::query()->find($this->exportId);

            if ($export === null || $export->status !== ReportExportStatus::Queued) {
                return;
            }

            $definition = $catalogue->find($export->report);

            if ($definition === null) {
                $this->fail($export, 'the named report is no longer in the catalogue');

                return;
            }

            $data = app($definition->builder)->build(
                CarbonImmutable::instance($export->window_from),
                CarbonImmutable::instance($export->window_to),
            );

            $file = $writer->write($data, $export->format);

            $storageKey = 'company/'.$export->company_id.'/export/'.$export->report.'/'.$export->id.'.'.$export->format->value;

            Storage::disk(DocumentStore::Disk)->put($storageKey, $file['bytes']);

            $export->update([
                'status' => ReportExportStatus::Ready->value,
                'storage_key' => $storageKey,
                'filename' => $export->report.'_'.$export->window_from->toDateString().'_to_'.$export->window_to->toDateString().'.'.$export->format->extension(),
                'bytes' => strlen($file['bytes']),
            ]);
        } finally {
            CurrentCompany::forget();
        }
    }

    public function failed(Throwable $exception): void
    {
        // The worker may not even reach handle(); the row must stop saying "queued" forever.
        $export = ReportExport::withoutGlobalScopes()->find($this->exportId);

        if ($export !== null && $export->status === ReportExportStatus::Queued) {
            $export->update([
                'status' => ReportExportStatus::Failed->value,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 255),
            ]);
        }
    }

    private function fail(ReportExport $export, string $reason): void
    {
        $export->update([
            'status' => ReportExportStatus::Failed->value,
            'failure_reason' => mb_substr($reason, 0, 255),
        ]);
    }
}
