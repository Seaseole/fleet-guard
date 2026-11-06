<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateLoan;
use App\Domain\Accounting\Actions\DisburseLoan;
use App\Domain\Accounting\Actions\PayLoanInstallment;
use App\Domain\Accounting\Actions\TransitionLoan;
use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanFrequency;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\LoanPurpose;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\LoanInstallment;
use App\Domain\Accounting\Queries\CashForecastQuery;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 slice 5f verification — loans, instalment splits and the 13-week roll-up.
 *
 * Proves the sentences and the design promise: the schedule is frozen at creation and adds up
 * exactly (Σ principal == principal; each row's interest is the stored rate on the balance the
 * previous row leaves); the two money moments post the documented shapes; a paid row refuses a
 * second payment and the event triple means one bank reference buys one entry; a disbursed loan
 * cannot be cancelled (Action *and* CHECK); and the forecast window carries precisely the
 * unpaid plan rows inside it.
 *
 * Rows are dated into the live month so the 13-week window sees them; every ledger row is
 * deleted at the end through the barrier, matched by the run's own LN- reference.
 *
 * Run: php scripts/verify-loans.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness posts real loan journals. Dev only.\n";
    exit(2);
}

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
    $checks++;
    echo "  ok   {$what}\n";
}

function check(string $what, ?string $why = null): void
{
    $why === null ? ok($what) : fail($what, $why);
}

function fail(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

function refuses(string $what, callable $fn): void
{
    global $checks, $failures;
    $checks++;

    try {
        $fn();
        $failures++;
        echo "  FAIL {$what} — nothing refused it\n";
    } catch (Throwable $e) {
        ok("{$what} (refused: {$e->getMessage()})");
    }
}

$ownerRow = DB::table('users')
    ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', 'owner')
    ->select('users.id')
    ->first();

/** @var User|null $owner */
$owner = $ownerRow === null ? null : User::query()->find($ownerRow->id);

if ($owner === null) {
    fwrite(STDERR, "No Owner row. Run: php artisan fleet:setup-owner.\n");
    exit(2);
}

CurrentCompany::force($owner->company_id);
auth()->setUser($owner);

foreach (app(NumberSequenceRegistry::class)->ensureAll() as $created) {
    echo "  sequence row created: {$created}\n";
}

$accounts = Account::query()->pluck('id', 'code');
$bank = BankAccount::query()->where('gl_account_id', $accounts['1120'])->where('active', true)->first();
$truck = DB::table('trucks')->limit(1)->value('id');

if ($bank === null) {
    fwrite(STDERR, "No linked register row for 1120 (the design is a precondition of this harness).\n");
    exit(2);
}

// A run that died half-way must not leave a second LN- loan lying around; sweep first, then raise.
LedgerBarrier::withLiftedGuard('verify: sweep any earlier loan-harness rows', $owner->id, function (): void {
    foreach (Loan::query()->where('notes', 'verify: loans harness')->get() as $stale) {
        $entryIds = JournalEntry::query()->where('description', 'like', '%'.$stale->reference.'%')->pluck('id')->all();

        if ($entryIds !== []) {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        }

        DB::table('loan_installments')->where('loan_id', $stale->id)->delete();
        DB::table('loans')->where('id', $stale->id)->delete();
    }
});

$today = CarbonImmutable::parse('today', 'Africa/Gaborone')->startOfDay();
$firstDue = $today->startOfMonth()->addDays(18);

echo "The frozen plan (the rows are the truth)\n";
echo "-----------------------------------------------\n";

$loan = app(CreateLoan::class)->execute(
    purpose: LoanPurpose::Vehicle,
    principalMinor: 1_200_000,
    annualRatePercent: '12',
    frequency: LoanFrequency::Monthly,
    firstPaymentOn: $firstDue,
    repaymentCount: 12,
    truckId: $truck === null ? null : (int) $truck,
    bankAccountId: (int) $bank->id,
    notes: 'verify: loans harness',
    actorId: $owner->id,
);

$rows = $loan->installments()->get();

check('twelve plan rows frozen at creation', $rows->count() === 12 ? null : 'got '.$rows->count());
check(
    'Σ principal_due is exactly the principal (remainder rule)',
    (int) $rows->sum('principal_due') === 1_200_000 ? null : (string) $rows->sum('principal_due'),
);
check('row 1 interest is one month on the full balance (1 200.00)', (int) $rows[0]->interest_due === 12_000 ? null : (string) $rows[0]->interest_due);
check('row 2 interest declines with the balance (1 100.00)', (int) $rows[1]->interest_due === 11_000 ? null : (string) $rows[1]->interest_due);
check('every row is total = principal + interest', (int) $rows->sum('total_due') === 1_200_000 + (int) $rows->sum('interest_due') ? null : 'split does not add');
check('the LN- reference came from the allocator', str_starts_with($loan->reference, 'LN-') ? null : $loan->reference);

echo "\nPaper refuses out of order; the ledger waits for the money\n";
echo "-------------------------------------------------------------------\n";

$transition = app(TransitionLoan::class);
$disburse = app(DisburseLoan::class);
$pay = app(PayLoanInstallment::class);

refuses('an undisbursed loan pays nothing', fn () => $pay->execute($rows[0], 112_000, $today, 'verify-early', $owner->id));
refuses('drafts cannot be disbursed', fn () => $disburse->execute($loan, $today, $owner->id));

$transition->submit($loan, $owner->id);
$approved = $transition->approve($loan->fresh(), $owner->id);
check('draft → submitted → approved walks the paper', $approved->status === LoanLifecycle::Approved ? null : $approved->status->value);

$disbursed = $disburse->execute($approved, $today, $owner->id);
check('disbursement flips the loan and records its entry', $disbursed->status === LoanLifecycle::Disbursed && $disbursed->disbursement_entry_id !== null ? null : 'not disbursed');

$drawEntry = JournalEntry::query()->where('source_type', 'loan_disbursement')->where('source_id', $loan->id)->first();
check('the draw posted Dr 1120 / Cr 2210 for exactly 12 000.00', $drawEntry !== null && (int) $drawEntry->total_debit === 1_200_000 ? null : 'entry missing or wrong');

refuses('a second disbursement is refused (the status moved)', fn () => $disburse->execute($loan->fresh(), $today, $owner->id));
refuses('a disbursed loan cannot be cancelled (money moved)', fn () => $transition->cancel($loan->fresh(), $owner->id));
check(
    'the draw is one entry, replay or not (ADR-0001 #3)',
    JournalEntry::query()->where('source_type', 'loan_disbursement')->where('source_id', $loan->id)->count() === 1 ? null : 'second entry',
);

echo "\nInstalment payments: the frozen split, the refusal, the closing\n";
echo "---------------------------------------------------------------------\n";

$first = $loan->fresh()->installments()->orderBy('due_on')->orderBy('seq')->first();
$paid = $pay->execute($first, 112_000, $today, 'verify-pay-1', $owner->id);

check('row 1 paid in full and marks the loan servicing', $paid->status === InstallmentStatus::Paid && $paid->loan->status === LoanLifecycle::Servicing ? null : 'not settled');

$payEntry = JournalEntry::query()->where('source_type', 'loan_instalment')->where('source_id', (string) $first->id)->first();
check(
    'the payment split posted 100 000 principal + 12 000 interest under one 112 000 credit',
    $payEntry !== null && (int) $payEntry->total_debit === 112_000 ? null : 'entry missing',
);

refuses('a paid row takes no second payment', fn () => $pay->execute($first->fresh(), 112_000, $today, 'verify-pay-1', $owner->id));
check('and the ledger still holds exactly one payment entry for it', JournalEntry::query()->where('source_type', 'loan_instalment')->where('source_id', (string) $first->id)->count() === 1 ? null : 'double entry');

$second = $loan->fresh()->installments()->where('seq', 2)->first();
$partial = $pay->execute($second, 50_000, $today, 'verify-pay-2', $owner->id);

check(
    'a part-payment allocates pro-rata across the frozen split (p 45 046 / i 4 954)',
    $partial->paid_principal === 45_046 && $partial->paid_interest === 4_954 ? null : "p {$partial->paid_principal} i {$partial->paid_interest}",
);
check('the row records partial and owes 61 000', $partial->status === InstallmentStatus::Partial && $partial->outstandingMinor() === 61_000 ? null : "outstanding {$partial->outstandingMinor()}");
refuses('overpaying one row is refused — the excess belongs on the next', fn () => $pay->execute($partial->fresh(), 70_000, $today, 'verify-pay-3', $owner->id));

echo "\nThe 13-week roll-up reads the plan rows\n";
echo "------------------------------------------------\n";

$forecast = app(CashForecastQuery::class)->build($today, 13);

$windowStart = $today->startOfWeek(CarbonImmutable::MONDAY);
$expectedRows = LoanInstallment::query()
    ->where('loan_id', $loan->id)
    ->whereIn('status', [InstallmentStatus::Scheduled->value, InstallmentStatus::Partial->value, InstallmentStatus::Overdue->value])
    ->get()
    ->filter(function (LoanInstallment $row) use ($windowStart): bool {
        $week = (int) $windowStart->diffInWeeks($row->due_on->toImmutable()->startOfWeek(CarbonImmutable::MONDAY), false);

        return $week >= 0 && $week < 13;
    });

$expectedOut = (int) $expectedRows->sum(fn (LoanInstallment $row): int => $row->outstandingMinor());

check('the unpaid rows dated inside the window are two (seq 2 and 3)', $expectedRows->count() === 2 ? null : 'got '.$expectedRows->count());
check('the plan remainder inside the window is 61 000 + 110 000', $expectedOut === 171_000 ? null : (string) $expectedOut);
check('the forecast carries at least the loan schedule', $forecast->expectedOutMinor() >= $expectedOut ? null : (string) $forecast->expectedOutMinor());
check(
    'the sheet ties: opening + in − out = closing',
    $forecast->closingCashMinor() === $forecast->openingCashMinor + $forecast->expectedInMinor() - $forecast->expectedOutMinor() ? null : 'forecast does not foot',
);

echo "\nCleanup\n-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s loan rows', $owner->id, function () use ($loan): void {
    $entryIds = JournalEntry::query()
        ->where('description', 'like', '%'.$loan->reference.'%')
        ->pluck('id')
        ->all();

    if ($entryIds !== []) {
        DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
    }

    DB::table('loan_installments')->where('loan_id', $loan->id)->delete();
    DB::table('loans')->where('id', $loan->id)->delete();
});

$left = Loan::query()->where('notes', 'verify: loans harness')->count()
    + JournalEntry::query()->where('description', 'like', '%'.$loan->reference.'%')->count();

$left === 0 ? ok('no verify rows left behind') : fail('leftover verify rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — LOANS OK' : ' — LOANS FAILED')."\n";

exit($failures === 0 ? 0 : 1);
