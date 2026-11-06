<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Support\AmortisationSchedule;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Raises a financing facility and freezes its plan (LOAN, the first three states).
 *
 * The schedule is built **at creation**, from the terms in this one call, and stored — the
 * loan row keeps the quoted rate for the paper trail, but the instalment rows are the truth the
 * forecast and the arrears list read (never recomputed at report time). Creating a loan
 * is paper only: nothing posts until disbursement, which is why `draft` and `submitted` need no
 * ledger contact and why the bank account is allowed to arrive unsigned (it is demanded at
 * disbursement, the moment money actually moves).
 */
final class CreateLoan
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function execute(
        LoanPurpose $purpose,
        int $principalMinor,
        string $annualRatePercent,
        LoanFrequency $frequency,
        DateTimeInterface|string $firstPaymentOn,
        int $repaymentCount,
        ?int $supplierId = null,
        ?int $truckId = null,
        ?int $bankAccountId = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): Loan {
        if (! Gate::allows('create', Loan::class)) {
            throw new InvalidArgumentException('Raising a loan needs the loans.manage ability.');
        }

        if ($principalMinor <= 0) {
            throw new InvalidArgumentException('A loan of zero is not credit; it is a mistake.');
        }

        if ($bankAccountId !== null) {
            $bank = BankAccount::query()->find($bankAccountId)
                ?? throw new InvalidArgumentException('That bank account is not in this company\'s register.');

            if ($bank->gl_account_id === null) {
                throw new InvalidArgumentException('The chosen repayment account is not linked to the ledger — a payment from it would have nowhere to leave.');
            }
        }

        $firstPaymentOn = Carbon::parse($firstPaymentOn)->startOfDay();

        $rows = AmortisationSchedule::build($principalMinor, $annualRatePercent, $frequency, $firstPaymentOn->toImmutable(), $repaymentCount);
        $base = CurrentCompany::model()->base_currency_code;

        return DB::transaction(function () use ($purpose, $principalMinor, $annualRatePercent, $frequency, $firstPaymentOn, $repaymentCount, $rows, $supplierId, $truckId, $bankAccountId, $notes, $actorId, $base): Loan {
            $reference = $this->numbers->allocate('loan', $firstPaymentOn, $actorId);

            $loan = new Loan([
                'id' => (string) Str::ulid(),
                'reference' => $reference,
                'status' => LoanLifecycle::Draft->value,
                'purpose' => $purpose->value,
                'supplier_id' => $supplierId,
                'truck_id' => $truckId,
                'bank_account_id' => $bankAccountId,
                'currency_code' => $base,
                'principal' => $principalMinor,
                'fx_rate' => '1',
                'fx_rate_source' => 'manual',
                'fx_rate_date' => $firstPaymentOn->toDateString(),
                'base_currency_code' => $base,
                'base_principal' => $principalMinor,
                'interest_rate' => $annualRatePercent,
                'frequency' => $frequency->value,
                'first_payment_on' => $firstPaymentOn->toDateString(),
                'repayment_count' => $repaymentCount,
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            $loan->save();

            foreach ($rows as $row) {
                $loan->installments()->create([
                    'seq' => $row['seq'],
                    'due_on' => $row['due_on'],
                    'principal_due' => $row['principal_due'],
                    'interest_due' => $row['interest_due'],
                    'total_due' => $row['total_due'],
                    'paid_principal' => 0,
                    'paid_interest' => 0,
                    'status' => InstallmentStatus::Scheduled->value,
                ]);
            }

            return $loan;
        });
    }
}
