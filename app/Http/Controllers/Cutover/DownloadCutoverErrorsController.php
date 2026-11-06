<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutover;

use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverErrorReport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 0.5's downloadable error report — the file the accountant fixes the spreadsheet from.
 *
 * A route rather than a Livewire action because the response is a file: the browser needs a real
 * URL to hand to its download manager, and a URL is also what an audit trail can name. The policy
 * is asked here; the report itself is built by the Kernel support class, so the same bytes the
 * screen previews are the bytes that leave.
 */
final class DownloadCutoverErrorsController
{
    public function __invoke(CutoverBatch $batch): StreamedResponse
    {
        if (Gate::denies('view', $batch)) {
            abort(403, 'Your role may not read this cutover batch.');
        }

        return response()->streamDownload(
            function () use ($batch): void {
                echo CutoverErrorReport::build($batch);
            },
            CutoverErrorReport::filename($batch),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
