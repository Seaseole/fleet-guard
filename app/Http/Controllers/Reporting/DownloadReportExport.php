<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportExportFormat;
use App\Domain\Reporting\ReportsCatalogue;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a finished export to the seat that queued it — after the gates are asked again, and
 * after ⚑ re-auth for the financial ones (marks financial exports ⚑; the middleware is
 * invoked per row because a CSV of the daily board must not cost a dispatcher a password
 * check while a trial-balance workbook must).
 *
 * Shape copied from the Kernel's own download door: audit first, then stream; private
 * no-store headers; nothing served that is not Ready.
 */
final class DownloadReportExport
{
    public function __construct(
        private readonly ReportsCatalogue $catalogue,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request, ReportExport $export): StreamedResponse|RedirectResponse
    {
        Gate::authorize('download', $export);

        $definition = $this->catalogue->find($export->report);

        if ($definition === null) {
            abort(404);
        }

        if ($definition->category === 'financial') {
            /** @var RedirectResponse|null $reauth */
            $reauth = app(RequirePassword::class)->handle(
                $request,
                fn (Request $r) => null,
                'password.confirm',
                (int) config('fleet.identity.reauth_window_seconds'),
            );

            if ($reauth !== null) {
                return $reauth;
            }
        }

        if (! $export->isReady() || $export->storage_key === null || ! Storage::disk(DocumentStore::Disk)->exists($export->storage_key)) {
            abort(404, 'That export is not a finished file yet.');
        }

        // The trail row lands before the stream, the same way the document door does it:
        // a download that dies halfway is still a download that was attempted.
        $this->audit->security(SecurityEventKind::ExportDownloaded, [
            'export' => $export->id,
            'report' => $export->report,
            'format' => $export->format->value,
        ], (int) $request->user()->id);

        $mime = match ($export->format) {
            ReportExportFormat::Csv => 'text/csv; charset=UTF-8',
            ReportExportFormat::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ReportExportFormat::Pdf => 'application/pdf',
        };

        $stream = Storage::disk(DocumentStore::Disk)->readStream($export->storage_key);

        if (! is_resource($stream)) {
            abort(404, 'That export file could not be opened.');
        }

        return response()->stream(
            static function () use ($stream): void {
                $out = fopen('php://output', 'w');

                if ($out !== false) {
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                }

                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
