<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Policies;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportsCatalogue;
use App\Models\User;

/**
 * Who may stream an export out (an export is an authorised read, not a side door — and
 * the door is re-asked at download, not only at queue).
 *
 * Only the seat that queued a file may download it. The bytes carry exactly what that seat was
 * allowed to read; handing the row to a colleague would launder a narrower seat's window past
 * the matrix, which is the one thing the sentence forbids.
 */
final class ReportExportPolicy
{
    public function download(User $user, ReportExport $export): bool
    {
        if ((int) $export->actor_id !== (int) $user->id) {
            return false;
        }

        $definition = app(ReportsCatalogue::class)->find($export->report);

        if ($definition === null) {
            return false;
        }

        // Asked of *this* user, not the ambient session: a policy answers "may they", and the
        // caller's own seat must never be what the answer is really about.
        if ($definition->viewGate !== null && ! $user->can($definition->viewGate)) {
            return false;
        }

        return $user->can($definition->exportAbility);
    }
}
