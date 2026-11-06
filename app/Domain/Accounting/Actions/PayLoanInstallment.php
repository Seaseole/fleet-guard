<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\LoanInstallment;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The LoanInstallmentPaid — the split, read from stored rows, never recomputed: a payment
 * posts Dr 2210 for the principal part and Dr 5500 for the interest part against one Cr on the
 * bank, and each side takes exactly the share the plan froze at origination.
 *
 * A part-payment allocates pro-rata across the row's own split (principal first? no — the split
 * as the plan states it, scaled), records `partial`, and can be followed by another; the row is
 * `paid` when both counters meet the due amounts, and the loan closes itself when the *last*
 * row closes (the `servicing → closed`, derived, not announced).
 *
 * The caller passes a `paymentRef` that makes the posting idempotent: the same bank
 * fact delivered twice posts once. The ledger entry id lands on the row, so the payment trail
 * runs instalment → entry → journal browser without a join table.
 *
 * Deviation stated rather than hidden: The design names `6500 Interest expense` — COA v1 has 5500
 * Vehicle finance and insurance, which is where a corridor fleet's finance charges already
 * land (insurance and loan interest share the account at this chart's granularity).
 */
final class PayLoanInstallment
{
    private const LIABILITY_ACCOUNT = '2210';

    private const INTEREST_ACCOUNT = '5500';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(
        LoanInstallment $instalment,
        int $amountMinor,
        DateTimeInterface|string $paidOn,
        string $paymentRef,
        ?int $actorId = null,
    ): LoanInstallment {
        $instalment->loadMissing('loan');
        $loan = $instalment->loan;

        if ($loan === null || ! Gate::allows('manage', $loan)) {
            throw new InvalidArgumentException('Paying an instalment needs the loans.manage ability over its loan.');
        }

        if (! in_array($loan->status, [LoanLifecycle::Disbursed, LoanLifecycle::Servicing, LoanLifecycle::Arrears, LoanLifecycle::Restructured], true)) {
            throw new InvalidArgumentException('Only a drawn facility pays instalments — a draft loan that pays is a paper error.');
        }

        if ($paymentRef === '') {
            throw new InvalidArgumentException('A payment needs its bank reference — the idempotency key that stops one debit buying two instalments.');
        }

        $paidOn = Carbon::parse($paidOn)->startOfDay();
        $base = CurrentCompany::model()->base_currency_code;
        $accounts = $this->chart->byCode();

        $liability = $accounts[self::LIABILITY_ACCOUNT] ?? null;
        $interest = $accounts[self::INTEREST_ACCOUNT] ?? null;

        if ($liability === null) {
            throw new InvalidArgumentException('Account 2210 Vehicle finance loans is missing from this company\'s chart.');
        }

        if ($interest === null) {
            throw new InvalidArgumentException('Account 5500 Vehicle finance and insurance is missing from this company\'s chart.');
        }

        $bank = $loan->bankAccount;

        if ($bank === null) {
            throw new InvalidArgumentException('The loan has no bank account to pay from — set it before the first instalment.');
        }

        if ($bank->gl_account_id === null) {
            throw new InvalidArgumentException('The payment bank is not linked to a ledger account.');
        }

        $dimensions = $loan->truck_id === null ? [] : ['truck_id' => $loan->truck_id];

        // The row's own money is read under its lock, and the posting is built from what the lock
        // returned: two payments against one instalment must not both see the same outstanding figure
        // and both move the counters past the plan.
        DB::transaction(function () use ($loan, $instalment, $amountMinor, $paidOn, $paymentRef, $base, $liability, $interest, $bank, $dimensions, $actorId): void {
            /** @var LoanInstallment $locked */
            $locked = LoanInstallment::query()->whereKey($instalment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOutstanding()) {
                throw new InvalidArgumentException('That row is already paid or superseded; nothing is owed on it.');
            }

            $due = $locked->outstandingMinor();

            if ($amountMinor <= 0 || $amountMinor > $due) {
                throw new InvalidArgumentException("A payment must be between 1 and {$due} minor units — this row owes exactly that, and overpaying an instalment belongs on the next row or the reconciliation, not this one.");
            }

            // Pro-rata across the row's own frozen split, remainder to principal so the interest
            // never inflates past the plan: the books may owe *less* interest, never more.
            $interestPaid = intdiv($amountMinor * $locked->interest_due, $locked->total_due);
            $principalPaid = $amountMinor - $interestPaid;

            // A payment cannot exceed its side of the plan; if rounding pushed principal over the
            // unpaid principal, move the excess back to interest.
            $principalRemaining = $locked->principal_due - $locked->paid_principal;

            if ($principalPaid > $principalRemaining) {
                $interestPaid += $principalPaid - $principalRemaining;
                $principalPaid = $principalRemaining;
            }

            $posting = PostingDto::inBaseCurrency(
                description: "Loan {$loan->reference} instalment {$locked->seq} paid",
                postedOn: $paidOn,
                baseCurrencyCode: $base,
                lines: array_values(array_filter([
                    $principalPaid > 0 ? new LineDto($liability->id, Side::Debit, Money::ofMinor($principalPaid, $base), note: 'principal', dimensions: $dimensions) : null,
                    $interestPaid > 0 ? new LineDto($interest->id, Side::Debit, Money::ofMinor($interestPaid, $base), note: 'interest', dimensions: $dimensions) : null,
                    new LineDto((int) $bank->gl_account_id, Side::Credit, Money::ofMinor($amountMinor, $base), note: $paymentRef, dimensions: $dimensions),
                ])),
                sourceType: 'loan_instalment',
                sourceId: (string) $locked->id,
                // source_event_id is char(26): the row id is already the source_id, so the event key
                // only has to separate *this bank reference* from earlier ones — a hash prefix is
                // stable and short, and 'same ref twice' collapses onto one entry exactly as intended.
                sourceEventId: 'paid-'.substr(sha1($paymentRef), 0, 20),
                createdBy: $actorId,
            );

            // Before any row moves: a redelivery of the same bank fact must not double-count
            // the paid counters even though the ledger itself would collapse the post. The door is
            // the Kernel's own replay lookup, not a second query language — and it is read inside the
            // lock, so the loser of a race on one reference stops instead of paying again.
            if ($this->ledger->findPosted($posting) !== null) {
                return;
            }

            $entry = $this->ledger->post($posting);

            $locked->paid_principal += $principalPaid;
            $locked->paid_interest += $interestPaid;
            $settled = $locked->paid_principal >= $locked->principal_due
                && $locked->paid_interest >= $locked->interest_due;

            $locked->forceFill([
                'status' => $settled ? InstallmentStatus::Paid->value : InstallmentStatus::Partial->value,
                'paid_at' => $settled ? now() : null,
                'entry_id' => $entry->id,
            ])->save();

            if ($loan->status === LoanLifecycle::Disbursed) {
                $loan->forceFill(['status' => LoanLifecycle::Servicing->value])->save();
            }

            $settledAndLast = $settled && $loan->installments()->where('status', '!=', InstallmentStatus::Paid->value)->count() === 0;

            if ($settledAndLast) {
                $from = $loan->status->value;
                $loan->forceFill(['status' => LoanLifecycle::Closed->value])->save();

                $this->audit->operational('loan', $loan->id, $from, LoanLifecycle::Closed->value, reason: 'final instalment settled — the facility is closed', actorId: $actorId);
            }

            $this->audit->operational(
                'loan_instalment',
                (string) $locked->id,
                null,
                $settled ? InstallmentStatus::Paid->value : InstallmentStatus::Partial->value,
                reason: "instalment {$locked->seq} of {$loan->reference}: {$amountMinor} minor units (p {$principalPaid} / i {$interestPaid})",
                context: ['entry_no' => $entry->entry_no],
                actorId: $actorId,
            );
        }, 3);

        return $instalment->refresh();
    }
}
