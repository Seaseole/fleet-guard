<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The accountant's line through the calendar (Phase 5's "a real month closes").
 *
 * Closing is a status transition on the period row — `open → closed` — and the enforcement is
 * layered exactly as the design lays it: this row plus the `closed` refusal in
 * {@see LedgerService::post()} is what stops the application posting
 * into the month, and `locked` (see {@see LockPeriod}) is what stops a SQL client. The verdict
 * on *whether the month may close* deliberately does not live here: the checks are Accounting's
 * (ledger verification, tie-out, reconciliations) and the periods screen runs them before it
 * lets a person through — the Kernel keeps the mechanics, the module keeps the judgement, which
 * is the dependency rule (D2) pointing the right way.
 *
 * The `gl_periods_closure_recorded` CHECK (the row) is why `closed_at` travels with the flip:
 * a closed month without its instant is a data-entry trick, not a close.
 */
final class ClosePeriod
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(string $periodKey, ?int $actorId = null): Period
    {
        if (! Gate::allows('ledger.period.close')) {
            throw new InvalidArgumentException('Closing an accounting period needs the ledger.period.close ability.');
        }

        /** @var Period|null $period */
        $period = Period::query()
            ->where('company_id', CurrentCompany::id())
            ->where('period', $periodKey)
            ->first();

        if ($period === null) {
            throw new InvalidArgumentException("There is no {$periodKey} period row — a month with no postings has nothing to close.");
        }

        if (! $period->isOpen()) {
            throw new InvalidArgumentException("The {$periodKey} period is already {$period->status}; closing is a one-way flip per month.");
        }

        $period->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actorId,
        ])->save();

        $this->audit->operational(
            'gl_period',
            $period->period,
            'open',
            'closed',
            reason: 'month closed after pre-close validation',
            context: ['starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()],
            actorId: $actorId,
        );

        return $period;
    }
}
