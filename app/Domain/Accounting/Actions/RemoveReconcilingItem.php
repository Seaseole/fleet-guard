<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Models\ReconcilingItem;
use App\Domain\Accounting\ReconciliationStatus;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Removes a reconciling line from an open run (Phase 5, slice 5d). Only the accountant
 * who entered the wrong line needs this, and only before the signature — an approved or voided
 * run keeps every line it was signed with, so the removal itself is a refusal, not a state.
 */
final class RemoveReconcilingItem
{
    public function execute(ReconcilingItem $item): void
    {
        /** @var Reconciliation $reconciliation */
        $reconciliation = $item->reconciliation;

        if (! Gate::allows('mutate', $reconciliation)) {
            throw new InvalidArgumentException($reconciliation->status === ReconciliationStatus::Open
                ? 'Editing this run needs the bank.reconcile ability.'
                : 'A signed or voided run keeps its lines — void it and start a new one.');
        }

        $item->delete();
    }
}
