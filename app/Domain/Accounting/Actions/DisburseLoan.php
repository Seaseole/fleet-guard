<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
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
 * `approved → disbursed` — the first moment a loan is a ledger fact (the LoanDisbursed:
 * Dr bank, Cr 2210 Vehicle finance loans; The cash-flow statement will show the draw as a
 * financing inflow because 2210 hangs off Non-current liabilities).
 *
 * The repayment account must be linked before this runs: money cannot arrive at a register row
 * the ledger does not know (the rule, enforced here rather than at read time). The event
 * triple `loan_disbursement / {loan id} / loan-{id}-disbursed` makes the disbursement exactly
 * once: a redelivered call returns the entry the first one created (ADR-0001 #3), it does not
 * double-fund the truck.
 *
 * Deviation from the table, stated rather than hidden: the table names `1010 Bank` and
 * `2500 Vehicle loan liability` — codes COA v1 does not have. The amendment note said these
 * rows were corrected to accounts that exist; the loan rows were missed. 1120/2210 are what the
 * chart actually holds, and inventing 1010/2500 would be a posting nobody's books contain.
 */
final class DisburseLoan
{
    private const LIABILITY_ACCOUNT = '2210';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Loan $loan, DateTimeInterface|string $on, ?int $actorId = null): Loan
    {
        if (! Gate::allows('manage', $loan)) {
            throw new InvalidArgumentException('Disbursing a loan needs the loans.manage ability.');
        }

        if ($loan->status !== LoanLifecycle::Approved) {
            throw new InvalidArgumentException('Only an approved loan can be disbursed.');
        }

        $on = Carbon::parse($on)->startOfDay();

        $bank = $loan->bankAccount
            ?? throw new InvalidArgumentException('Set the repayment/disbursement bank account before disbursement — the money must arrive somewhere the ledger sees.');

        if ($bank->gl_account_id === null) {
            throw new InvalidArgumentException('The loan\'s bank account is not linked to a ledger account — link it first, or the disbursement has no cash side.');
        }

        $accounts = $this->chart->byCode();

        // Null-then-throw rather than `?? throw` on the chart lookup: '2210' is a numeric-string
        // key PHP has already turned into an int inside the returned array, and the inline
        // coalesce makes the analysis say the offset can never exist (trial balance trap).
        $liability = $accounts[self::LIABILITY_ACCOUNT] ?? null;

        if ($liability === null) {
            throw new InvalidArgumentException('Account 2210 Vehicle finance loans is not in this company\'s chart.');
        }

        $bankId = $this->accountIdOf($accounts, (int) $bank->gl_account_id);
        $base = CurrentCompany::model()->base_currency_code;
        $amount = Money::ofMinor($loan->base_principal, $base);

        $dimensions = $loan->truck_id === null ? [] : ['truck_id' => $loan->truck_id];

        DB::transaction(function () use ($loan, $on, $bankId, $liability, $amount, $base, $dimensions, $actorId) {
            $entry = $this->ledger->post(PostingDto::inBaseCurrency(
                description: "Loan {$loan->reference} disbursed",
                postedOn: $on,
                baseCurrencyCode: $base,
                lines: [
                    new LineDto($bankId, Side::Debit, $amount, note: 'facility drawn', dimensions: $dimensions),
                    new LineDto($liability->id, Side::Credit, $amount, note: $loan->reference, dimensions: $dimensions),
                ],
                sourceType: 'loan_disbursement',
                sourceId: $loan->id,
                // source_event_id is char(26): the event key is just 'disbursed' because the
                // source triple already carries the loan id (the idempotency needs no longer word).
                sourceEventId: 'disbursed',
                createdBy: $actorId,
            ));

            $loan->forceFill([
                'status' => LoanLifecycle::Disbursed->value,
                'disbursed_on' => $on->toDateString(),
                'disbursement_entry_id' => $entry->id,
            ])->save();

            $this->audit->operational(
                'loan',
                $loan->id,
                LoanLifecycle::Approved->value,
                LoanLifecycle::Disbursed->value,
                reason: "facility drawn: {$loan->base_principal} minor units to {$loan->bankAccount?->name}",
                context: ['entry_no' => $entry->entry_no],
                actorId: $actorId,
            );
        });

        return $loan;
    }

    /** @param array<string, ChartAccount> $accounts */
    private function accountIdOf(array $accounts, int $glAccountId): int
    {
        foreach ($accounts as $account) {
            if ($account->id === $glAccountId) {
                return $account->id;
            }
        }

        throw new InvalidArgumentException('The linked ledger account vanished from the chart between the register and this posting.');
    }
}
