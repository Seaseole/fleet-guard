<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Reopening a closed month — deliberately the only undo a close has.
 *
 * It exists because closing is performed by a person and people close the wrong month when the
 * tie-out report is ambiguous; the alternative — "a closed month is eternal, work around it" —
 * pushes corrections into *later* months and quietly misstates two periods instead of one. A
 * locked month cannot be reopened: that flip is the forensic boundary and reversing it would
 * make the trigger a decoration.
 *
 * The reason is mandatory and audited, like the void on a reconciliation: the reopen itself is
 * the event an auditor asks about, so it must arrive with its sentence.
 */
final class ReopenPeriod
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(string $periodKey, string $reason, ?int $actorId = null): Period
    {
        if (! Gate::allows('ledger.period.close')) {
            throw new InvalidArgumentException('Reopening an accounting period needs the ledger.period.close ability.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reopen needs its sentence — say why the closed month must accept postings again.');
        }

        /** @var Period|null $period */
        $period = Period::query()
            ->where('company_id', CurrentCompany::id())
            ->where('period', $periodKey)
            ->first();

        if ($period === null) {
            throw new InvalidArgumentException("There is no {$periodKey} period row to reopen.");
        }

        if ($period->isLocked()) {
            throw new InvalidArgumentException("The {$periodKey} period is locked — that boundary is forensic and does not reopen (locked is the state the trigger refuses).");
        }

        if ($period->isOpen()) {
            throw new InvalidArgumentException("The {$periodKey} period is already open.");
        }

        $period->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null])->save();

        $this->audit->operational(
            'gl_period',
            $period->period,
            'closed',
            'open',
            reason: $reason,
            actorId: $actorId,
        );

        return $period;
    }
}
