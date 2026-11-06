<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\AddReconcilingItem;
use App\Domain\Accounting\Actions\ApproveReconciliation;
use App\Domain\Accounting\Actions\RemoveReconcilingItem;
use App\Domain\Accounting\Actions\StartReconciliation;
use App\Domain\Accounting\Actions\VoidReconciliation;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Reconciliation;
use App\Domain\Accounting\Models\ReconcilingItem;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Accounting\ReconciliationStatus;
use App\Domain\Accounting\ReconcilingItemDirection;
use App\Domain\Accounting\ReconcilingItemKind;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 slice 5d verification — the bank reconciliation.
 *
 * Not the test suite (tests are local-only by owner decision). This is the runnable
 * proof that the signature screen behaves like a control: it refuses unlinked accounts and a
 * second live run for the day, refuses approval while a variance is open, signs exactly at
 * zero while the walk shows the items that closed it, keeps a signed run immutable, and lets
 * a void free the statement date for the retry.
 *
 * Run: php scripts/verify-reconciliation.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness writes control records and ledger rows. Dev only.\n";
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

// The whole harness runs as the Owner seat: the Actions re-check `bank.reconcile` through the
// Gate, and only a signed-in seat can pass a policy (the "a screen is not a permission
// check" cuts both ways — so does an authless CLI).
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

foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse('2099-04-15')) as $created) {
    echo "  sequence row created: {$created}\n";
}

$ledger = app(LedgerService::class);
$math = app(ReconciliationMath::class);
$base = CurrentCompany::model()->base_currency_code;
$money = fn (int $minor): Money => Money::ofMinor($minor, $base);
$accounts = Account::query()->pluck('id', 'code');

echo "Setup — a linked register row and movement to reconcile\n";
echo "--------------------------------------------------------\n";

$account = BankAccount::query()->where('gl_account_id', $accounts['1120'])->where('active', true)->first();
$createdAccountId = null;

if ($account === null) {
    $account = new BankAccount([
        'name' => 'Verify reconciliation bank',
        'institution' => 'Verification Bank',
        'currency_code' => $base,
        'type' => 'bank',
        'gl_account_id' => (int) $accounts['1120'],
        'active' => true,
    ]);
    $account->save();
    $createdAccountId = $account->id;
    ok('register row created and linked to 1120');
} else {
    ok('reused an existing linked register row');
}

$ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: reconciliation receipt',
    postedOn: CarbonImmutable::parse('2099-04-10'),
    baseCurrencyCode: $base,
    lines: [
        new LineDto((int) $accounts['1120'], Side::Debit, $money(250_000), note: 'customer paid'),
        new LineDto((int) $accounts['4900'], Side::Credit, $money(250_000), note: 'other income'),
    ],
    sourceType: 'verify_reconciliation',
    sourceEventId: 'verify-recon-1',
));

$live = $math->liveBookBase($account, CarbonImmutable::parse('2099-04-30'));
check('the ledger door answers at least the 2 500.00 we just posted', $live >= 250_000 ? null : "book read {$live}");

// The scenario speaks in offsets, not absolutes: the dev book already carries real history, so
// the statement is set 100.00 *below* whatever the ledger actually says. Every later figure in
// this harness is a variance, and variances do not care what the book starts at.
$statement = $live - 10_000;

echo "\nOpening refusals\n";
echo "--------------------------\n";

$start = app(StartReconciliation::class);

$unlinked = BankAccount::query()->whereNull('gl_account_id')->first();

if ($unlinked !== null) {
    refuses('an unlinked register row cannot be reconciled', fn () => $start->execute((int) $unlinked->id, CarbonImmutable::parse('2099-04-30'), 0, 'verify: unlinked refusal'));
} else {
    ok('no unlinked register row exists to refuse — guard untested this run');
}

$run = $start->execute($account->id, CarbonImmutable::parse('2099-04-30'), $statement, 'verify: reconciliation run', $owner->id);

check('the run opened', $run->exists && $run->status === ReconciliationStatus::Open ? null : 'no open run');
check('it carries its REC- number at creation', str_starts_with((string) $run->number, 'REC-') ? null : $run->number);

refuses('a second live run for the same day is refused', fn () => $start->execute($account->id, CarbonImmutable::parse('2099-04-30'), 1, 'verify: duplicate refusal'));

echo "\nThe walk and the refusal (a variance is chased, not signed)\n";
echo "---------------------------------------------------------------\n";

$run->load('bankAccount', 'items');
$variance = $math->varianceBase($run, $run->book_base_amount);
check('a statement 100.00 under the book opens with a −100.00 variance', $variance === -10_000 ? null : "variance {$variance}");

$approve = app(ApproveReconciliation::class);

refuses('approval is refused while the variance is open', fn () => $approve->execute($run->fresh(), $owner->id));

$add = app(AddReconcilingItem::class);
$addItem = fn (ReconcilingItemKind $kind, ReconcilingItemDirection $dir, int $minor) => $add->execute($run->fresh(), $kind, $dir, $minor, 'verify', null, '2099-04-29', $owner->id);

$addItem(ReconcilingItemKind::DepositOutstanding, ReconcilingItemKind::DepositOutstanding->suggestedDirection(), 6_000);
$addItem(ReconcilingItemKind::BankCharge, ReconcilingItemKind::BankCharge->suggestedDirection(), 4_400);

$variance = $math->varianceBase($run->fresh()->load('items'), $run->book_base_amount);
check('the deposit-in-transit and bank charge walk it to +4.00 over', $variance === 400 ? null : "variance now {$variance}");

refuses('a zero-amount line is refused — the sign lives in the direction', fn () => $addItem(ReconcilingItemKind::Other, ReconcilingItemDirection::Add, 0));

$cheque = $addItem(ReconcilingItemKind::ChequeUnpresented, ReconcilingItemKind::ChequeUnpresented->suggestedDirection(), 400);

$variance = $math->varianceBase($run->fresh()->load('items'), $run->book_base_amount);
check('the unpresented cheque walks it to zero', $variance === 0 ? null : "variance {$variance}");

echo "\nSigning, freezing, voiding\n";
echo "---------------------------\n";

$approved = $approve->execute($run->fresh()->load('bankAccount'), $owner->id);

check('the approved run froze its book snapshot, signer and instant', $approved->status === ReconciliationStatus::Approved
    && $approved->approved_by === $owner->id && $approved->approved_at !== null ? null : 'signature columns incomplete');

refuses('a signed run takes no new lines', fn () => $addItem(ReconcilingItemKind::Other, ReconcilingItemDirection::Add, 1));
refuses('a signed run takes no removals', fn () => (new RemoveReconcilingItem)->execute(ReconcilingItem::query()->find($cheque->id)));
refuses('a signed run cannot be approved twice', fn () => $approve->execute($approved->fresh(), $owner->id));

$voider = app(VoidReconciliation::class);

refuses('a void without a sentence is refused', fn () => $voider->execute($approved->fresh(), '   ', $owner->id));

$voided = $voider->execute($approved->fresh(), 'verify: correcting the statement figure', $owner->id);
check('the void keeps its lines as evidence', $voided->status === ReconciliationStatus::Void && $voided->items()->count() === 3 ? null : 'items lost');

$retry = $start->execute($account->id, CarbonImmutable::parse('2099-04-30'), $statement, 'verify: reconciliation retry', $owner->id);
check('the voided day is free for the retry', $retry->exists && $retry->status === ReconciliationStatus::Open ? null : 'restart refused');

$voider->execute($retry, 'verify: cleanup of the retry run', $owner->id);

echo "\nCleanup\n-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', $owner->id, function () use ($createdAccountId) {
    $ids = JournalEntry::query()->where('source_type', 'verify_reconciliation')->pluck('id')->all();

    if ($ids !== []) {
        DB::table('gl_journal_lines')->whereIn('entry_id', $ids)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $ids)->delete();
    }

    $runIds = Reconciliation::query()->where('note', 'like', 'verify: reconciliation%')->pluck('id')->all();

    if ($runIds !== []) {
        DB::table('reconciling_items')->whereIn('reconciliation_id', $runIds)->delete();
        DB::table('reconciliations')->whereIn('id', $runIds)->delete();
    }

    if ($createdAccountId !== null) {
        DB::table('bank_accounts')->where('id', $createdAccountId)->delete();
    }

    Period::query()->where('period', '2099-04')->delete();
});

LedgerBarrier::isLifted()
    ? fail('cleanup left the barrier raised')
    : ok('barrier lowered again');

$left = Reconciliation::query()->where('note', 'like', 'verify: reconciliation%')->count()
    + ReconcilingItem::query()->where('reference', 'verify')->count()
    + JournalEntry::query()->where('source_type', 'verify_reconciliation')->count();

$left === 0 ? ok('no verify rows left behind') : fail('leftover verify rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — RECONCILIATION OK' : ' — RECONCILIATION FAILED')."\n";

exit($failures === 0 ? 0 : 1);
