<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Audit\AuditRecorder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Voids a reconciliation run — the correction path for both an abandoned attempt and a wrong
 * signature (Phase 5, slice 5d). Nothing is edited and nothing is deleted: the voided run
 * keeps its statement figure, its book snapshot and every line, and the freed statement day
 * (the live-row unique index) lets a fresh run take the same date with better figures.
 *
 * The reason is mandatory because a void without a sentence is the document an auditor asks
 * about first.
 */
final class VoidReconciliation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(Reconciliation $reconciliation, string $reason, ?int $actorId = null): Reconciliation
    {
        if (! Gate::allows('void', $reconciliation)) {
            throw new InvalidArgumentException($reconciliation->status === ReconciliationStatus::Void
                ? 'The run is already void.'
                : 'Voiding a reconciliation needs the bank.reconcile ability.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A void needs its sentence — say why this run is discarded.');
        }

        $from = $reconciliation->status->value;

        $reconciliation->forceFill(['status' => ReconciliationStatus::Void->value])->save();

        $this->audit->operational(
            'reconciliation',
            $reconciliation->id,
            $from,
            ReconciliationStatus::Void->value,
            reason: $reason,
            actorId: $actorId,
        );

        return $reconciliation;
    }
}
