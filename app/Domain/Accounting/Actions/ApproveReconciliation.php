<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Kernel\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The signature (Phase 5, slice 5d): the accountant says the bank's figure and the book,
 * walked through its reconciling items, agree **to the minor unit** — or nothing happens.
 *
 * There is no "approve with variance" path on purpose. A signed reconciliation that does not
 * reconcile is the document an auditor later cannot question, and the whole trust model says
 * a disagreement is a fact to chase, not a tolerance to tune. The refusal names the exact figure
 * so the clerk knows which side moved; the fix is more items, a corrected statement entry, or a
 * manual journal — all of which change the *inputs*, never this rule.
 *
 * Approval re-reads the live book and re-freezes `book_base_amount` at the instant of signing:
 * the snapshot is the moment the person saw what they signed, which is what "approved" means.
 */
final class ApproveReconciliation
{
    public function __construct(
        private readonly ReconciliationMath $math,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Reconciliation $reconciliation, ?int $actorId = null): Reconciliation
    {
        if (! Gate::allows('approve', $reconciliation)) {
            throw new InvalidArgumentException($reconciliation->status === ReconciliationStatus::Open
                ? 'Signing a reconciliation needs the bank.reconcile ability.'
                : 'Only an open run can be approved.');
        }

        return DB::transaction(function () use ($reconciliation, $actorId): Reconciliation {
            $reconciliation->load('bankAccount', 'items');

            $book = $this->math->liveBookBase($reconciliation->bankAccount, $reconciliation->statement_on);
            $variance = $this->math->varianceBase($reconciliation, $book);

            if ($variance !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'The statement is off by %d minor units against book %+d plus the items — approval refused (a disagreement is a fact to chase, not a tolerance to tune).',
                    $variance,
                    $book,
                ));
            }

            $reconciliation->forceFill([
                'status' => ReconciliationStatus::Approved->value,
                'book_base_amount' => $book,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ])->save();

            $this->audit->operational(
                'reconciliation',
                $reconciliation->id,
                ReconciliationStatus::Open->value,
                ReconciliationStatus::Approved->value,
                reason: 'statement and book agree to the minor unit',
                context: ['book_base' => $book, 'statement_base' => $reconciliation->base_amount],
                actorId: $actorId,
            );

            return $reconciliation;
        });
    }
}
