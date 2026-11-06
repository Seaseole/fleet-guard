<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\Supplier;
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
 * Phase 0.5's opening facility — a loan the fleet was already paying when the books changed
 *.
 *
 * The trial balance can carry the liability: `2210 Vehicle finance loans` asks no dimension (the
 * mask), so a control total is legal there. What it cannot carry is **when the money falls due** —
 * which is the entire content of the Phase 5 promise, "13-week cash forecast from known
 * schedules". The forecast walks `loan_installments`, so a facility that exists only as one credit on
 * the balance sheet is invisible to it: the books balance and the calendar is wrong.
 *
 * Three things follow from that, and all three are the reason this is its own Action rather than a
 * call to {@see CreateLoan}.
 *
 * 1. **Nothing posts.** The drawdown happened in the old system's year, out of the old system's bank.
 *    The disbursement entry (Dr `1120` / Cr `2210`) would put cash on this balance sheet that the
 *    accountant's opening figures already exclude, and would post the liability a second time beside
 *    the trial balance's line. So the row carries no `disbursement_entry_id`, and the ledger hears
 *    about this facility only when an instalment is paid here.
 * 2. **The plan is rebuilt, not copied.** The design forbids storing an instalment schedule that a report
 *    recomputes, and forbids recomputing one that a report reads — so the remaining principal and the
 *    remaining count go through {@see AmortisationSchedule}, the same rule froze at signing,
 *    and the rows land as *this* book's rows. A legacy statement's paid rows are not imported: the
 *    file says what is still owing, and the amount that answers for it is the trial balance's.
 * 3. **`disbursed`, and not `servicing`.** This book has never seen a payment on this facility, so it
 *    cannot claim one is being made; The design's own door promotes the row to `servicing` the first time
 *    an instalment settles here. `disbursed` is already in the forecast's status list, so the calendar
 *    is right on day one either way.
 *
 * The legacy loan number is the natural key, held the way every other opening document holds one: a
 * `source_uuid` of `opening-loan:{number}`, unique per company. The facility's own
 * `reference` comes from today's sequence — the register is starting, not being back-filled — which is
 * exactly why the legacy number needs a column of its own to be found again by.
 */
final class RecordOpeningLoan
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * @param  int  $principalMinor  the principal still owing, in base minor units
     * @param  int  $repaymentCount  the instalments still to come, not the original term
     */
    public function execute(
        string $legacyReference,
        int $principalMinor,
        string $annualRatePercent,
        LoanFrequency $frequency,
        int $repaymentCount,
        DateTimeInterface $nextPaymentOn,
        LoanPurpose $purpose = LoanPurpose::Vehicle,
        ?int $supplierId = null,
        ?int $truckId = null,
        ?int $bankAccountId = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): Loan {
        if (! Gate::allows('create', Loan::class)) {
            throw new InvalidArgumentException('Carrying a facility onto this book needs the loans.manage ability.');
        }

        if ($principalMinor <= 0) {
            throw new InvalidArgumentException('A facility with nothing left to pay is closed, and the design says its own door says so.');
        }

        if ($repaymentCount < 1) {
            throw new InvalidArgumentException('An opening loan needs at least one instalment still to come — with none, the file is describing a paid-off facility.');
        }

        $reference = trim($legacyReference);

        if ($reference === '' || mb_strlen($reference) > 40) {
            throw new InvalidArgumentException('The legacy loan number is the natural key of an opening facility — it must be present and no longer than 40 characters.');
        }

        // The lookup comes before anything that could refuse a retry (the lesson): a batch that
        // died halfway has already raised its earlier facilities, and a guard that asks "is this
        // amount positive?" on its way past them would make the intake unre-runnable.
        $sourceUuid = 'opening-loan:'.mb_strtolower($reference);

        /** @var Loan|null $already */
        $already = Loan::query()->where('source_uuid', $sourceUuid)->first();

        if ($already !== null) {
            return $already;
        }

        if ((float) $annualRatePercent < 0) {
            throw new InvalidArgumentException('A negative rate is a data entry mistake, not a subsidy.');
        }

        $dueFrom = Carbon::parse($nextPaymentOn)->startOfDay();

        if ($supplierId !== null) {
            Supplier::query()->find($supplierId)
                ?? throw new InvalidArgumentException('That lender is not in this company\'s supplier register.');
        }

        if ($bankAccountId !== null) {
            $bank = BankAccount::query()->find($bankAccountId)
                ?? throw new InvalidArgumentException('That bank account is not in this company\'s register.');

            if ($bank->gl_account_id === null) {
                throw new InvalidArgumentException('The chosen repayment account is not linked to the ledger — a payment from it would have nowhere to leave.');
            }
        }

        $rows = AmortisationSchedule::build($principalMinor, $annualRatePercent, $frequency, $dueFrom->toImmutable(), $repaymentCount);
        $base = CurrentCompany::model()->base_currency_code;
        $raisedOn = Carbon::now()->startOfDay();

        return DB::transaction(function () use ($purpose, $reference, $principalMinor, $annualRatePercent, $frequency, $dueFrom, $repaymentCount, $rows, $supplierId, $truckId, $bankAccountId, $notes, $actorId, $base, $sourceUuid, $raisedOn): Loan {
            $number = $this->numbers->allocate('loan', $raisedOn, $actorId);

            $loan = new Loan([
                'id' => (string) Str::ulid(),
                'reference' => $number,
                // Money has moved — in the old system, whose bank this book never saw. No
                // `disbursement_entry_id`, because no entry was made here (the decision 1).
                'status' => LoanLifecycle::Disbursed->value,
                'purpose' => $purpose->value,
                'supplier_id' => $supplierId,
                'truck_id' => $truckId,
                'bank_account_id' => $bankAccountId,
                'currency_code' => $base,
                'principal' => $principalMinor,
                'fx_rate' => '1',
                'fx_rate_source' => 'opening_balance',
                'fx_rate_date' => $raisedOn->toDateString(),
                'base_currency_code' => $base,
                'base_principal' => $principalMinor,
                'interest_rate' => $annualRatePercent,
                'frequency' => $frequency->value,
                'first_payment_on' => $dueFrom->toDateString(),
                'repayment_count' => $repaymentCount,
                'notes' => $notes === null || trim($notes) === ''
                    ? sprintf('Carried from the legacy system as facility %s; the facility was drawn before cutover.', $reference)
                    : mb_substr(trim($notes), 0, 500),
                'source_uuid' => $sourceUuid,
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
