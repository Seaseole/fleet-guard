<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Reporting\Jobs\RunReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportExportFormat;
use App\Domain\Reporting\ReportExportStatus;
use App\Domain\Reporting\ReportsCatalogue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one door that queues an export.
 *
 * Everything a download would later re-ask is asked here first — the report exists, this seat
 * can read it, this seat holds the category's export ability — and the answer is stamped into
 * the trail the moment the file is promised (exports are audited rows, not incidents).
 * The job re-reads the gates at download too; asking twice is the point, because the bytes
 * leave on a different day than they were built and permissions move between the two.
 */
final class QueueReportExport
{
    public function __construct(
        private readonly ReportsCatalogue $catalogue,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $reportKey, ReportExportFormat $format, CarbonImmutable $from, CarbonImmutable $to, User $actor): ReportExport
    {
        $definition = $this->catalogue->find($reportKey);

        if ($definition === null) {
            throw new InvalidArgumentException("No report '{$reportKey}' is in the catalogue.");
        }

        if (! $definition->visibleToCurrentSeat()) {
            throw new InvalidArgumentException("This seat may not read '{$definition->title}' (the matrix).");
        }

        if (! Gate::allows($definition->exportAbility)) {
            throw new InvalidArgumentException("Exporting needs the '{$definition->exportAbility}' ability (an export is an authorised read).");
        }

        if ($from > $to) {
            $from = $to;
        }

        $export = ReportExport::create([
            'id' => (string) Str::ulid(),
            'report' => $definition->key,
            'format' => $format->value,
            'window_from' => $from->toDateString(),
            'window_to' => $to->toDateString(),
            'status' => ReportExportStatus::Queued->value,
            'actor_id' => $actor->id,
        ]);

        $this->audit->security(SecurityEventKind::ExportQueued, [
            'report' => $definition->key,
            'format' => $format->value,
            'window' => $from->toDateString().'..'.$to->toDateString(),
        ], $actor->id);

        RunReportExport::dispatch($export->id, $export->company_id)->onQueue('exports');

        return $export;
    }
}
