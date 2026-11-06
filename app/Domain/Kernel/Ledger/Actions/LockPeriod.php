<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Ledger\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * `closed → locked` — the step that moves the refusal below the application (R2's layer 4).
 *
 * A closed month is refused by the app; a locked month is refused by the DB trigger, which is
 * the state that survives a panic edit in a SQL client or a restore that skipped a guard. The
 * two states exist precisely because they are enforced by different parties: the accountant
 * closes, and *locking* is the deliberate "this book is now evidence" act — hence the extra
 * confirmation at the screen, and no path back. The application refuses locked here, and the
 * trigger refuses below it whatever this class missed.
 */
final class LockPeriod
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(string $periodKey, ?int $actorId = null): Period
    {
        if (! Gate::allows('ledger.period.close')) {
            throw new InvalidArgumentException('Locking an accounting period needs the ledger.period.close ability.');
        }

        /** @var Period|null $period */
        $period = Period::query()
            ->where('company_id', CurrentCompany::id())
            ->where('period', $periodKey)
            ->first();

        if ($period === null) {
            throw new InvalidArgumentException("There is no {$periodKey} period row to lock.");
        }

        if ($period->isLocked()) {
            throw new InvalidArgumentException("The {$periodKey} period is already locked.");
        }

        if ($period->isOpen()) {
            throw new InvalidArgumentException("The {$periodKey} period has not been closed — lock follows close, never replaces it.");
        }

        $period->forceFill(['status' => 'locked'])->save();

        $this->audit->operational(
            'gl_period',
            $period->period,
            'closed',
            'locked',
            reason: 'period locked — the book is evidence from this instant',
            actorId: $actorId,
        );

        return $period;
    }
}
