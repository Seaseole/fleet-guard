<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Loan;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The three paper moves of before any money exists: draft → submitted → approved, and the
 * `approved → cancelled` door that is only legal while nothing has disbursed.
 *
 * They live in one class because they are one concern — the *decision chain* on the paper — and
 * every one of them answers the same two questions in the same order: may this seat move this
 * loan, and is the loan in the state this move presumes. Disbursement and payment are ledger
 * acts and live in their own Actions; a cancelled loan never had one.
 */
final class TransitionLoan
{
    public function submit(Loan $loan, ?int $actorId = null): Loan
    {
        $this->guard($loan);

        if ($loan->status !== LoanLifecycle::Draft) {
            throw new InvalidArgumentException('Only a draft loan can be submitted — from submission the terms are the ones a signer sees.');
        }

        $loan->forceFill(['status' => LoanLifecycle::Submitted->value])->save();

        return $loan;
    }

    public function approve(Loan $loan, ?int $actorId = null): Loan
    {
        $this->guard($loan);

        if ($loan->status !== LoanLifecycle::Submitted) {
            throw new InvalidArgumentException('A loan is approved from submitted — nothing is approved that nobody submitted.');
        }

        $loan->forceFill(['status' => LoanLifecycle::Approved->value])->save();

        return $loan;
    }

    public function cancel(Loan $loan, ?int $actorId = null): Loan
    {
        $this->guard($loan);

        if (! $loan->status->canStillCancel()) {
            throw new InvalidArgumentException('A loan whose money has moved is corrected by postings, not cancelled (; the loans CHECK says it too).');
        }

        $loan->forceFill(['status' => LoanLifecycle::Cancelled->value])->save();

        return $loan;
    }

    private function guard(Loan $loan): void
    {
        if (! Gate::allows('manage', $loan)) {
            throw new InvalidArgumentException('Moving a loan through its paper states needs the loans.manage ability.');
        }
    }
}
