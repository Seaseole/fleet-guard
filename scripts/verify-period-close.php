<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveReconciliation;
use App\Domain\Accounting\Actions\StartReconciliation;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Queries\ClosePeriodValidation;
use App\Domain\Accounting\Queries\ReconciliationMath;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Ledger\Actions\ClosePeriod;
use App\Domain\Kernel\Ledger\Actions\LockPeriod;
use App\Domain\Kernel\Ledger\Actions\ReopenPeriod;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 slice 5e verification — the period close (the "a real month closes").
 *
 * Proves the three-state machine and who enforces each state: the pre-close report answers red
 * until the cash movement is reconciled; close refuses further postings at the application door
 * (and reopen needs its sentence, then lets them back); lock moves the refusal below the
 * application and cannot be undone. All rows this creates live in 2099-05 and are removed at the
 * end through the barrier, exactly like the ledger harness.
 *
 * Run: php scripts/verify-period-close.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness closes and locks periods. Dev only.\n";
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

function refuses(string $what, callable $fn, ?string $expectClass = null): void
{
    global $checks, $failures;
    $checks++;

    try {
        $fn();
        $failures++;
        echo "  FAIL {$what} — nothing refused it\n";
    } catch (Throwable $e) {
        if ($expectClass !== null && ! $e instanceof $expectClass) {
            $failures++;
            echo "  FAIL {$what} — wrong refusal: ".get_class($e)."\n";

            return;
        }
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

foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse('2099-05-15')) as $created) {
    echo "  sequence row created: {$created}\n";
}

$ledger = app(LedgerService::class);
$validation = app(ClosePeriodValidation::class);
$math = app(ReconciliationMath::class);
$base = CurrentCompany::model()->base_currency_code;
$money = fn (int $minor): Money => Money::ofMinor($minor, $base);
$accounts = Account::query()->pluck('id', 'code');

echo "Setup — one month, one cash-moving journal\n";
echo "-------------------------------------------\n";

$journal = function (string $day, string $event) use ($ledger, $base, $accounts, $money): void {
    $ledger->post(PostingDto::inBaseCurrency(
        description: 'verify: period close journal',
        postedOn: CarbonImmutable::parse("2099-05-{$day}"),
        baseCurrencyCode: $base,
        lines: [
            new LineDto((int) $accounts['1120'], Side::Debit, $money(50_000)),
            new LineDto((int) $accounts['4900'], Side::Credit, $money(50_000)),
        ],
        sourceType: 'verify_period_close',
        sourceEventId: $event,
    ));
};

$journal('10', 'verify-close-1');
$journal('12', 'verify-close-2');

$report = $validation->run('2099-05');

check('the month has its period row and it is open', $report['period'] !== null && $report['period']['status'] === 'open' ? null : 'missing or not open');

$cashRow = collect($report['checks'])->firstWhere('key', 'cash_reconciled');
check('the pre-close report is red while cash moved un-reconciled', $cashRow !== null && $cashRow['ok'] === false ? null : 'cash check unexpectedly passed');
check('a red blocking row keeps the close refused', $report['canClose'] === false ? null : 'canClose true with cash un-reconciled');

echo "\nReconciling the movement clears the cash row (feeding )\n";
echo "-------------------------------------------------------------------\n";

$account = DB::table('bank_accounts')->where('gl_account_id', $accounts['1120'])->where('active', 1)->whereNull('closed_on')->first();

if ($account === null) {
    fail('no linked active register row for 1120 — link is a precondition of this harness');
    echo "\n{$checks} checks, {$failures} failed — PERIOD CLOSE FAILED\n";
    exit(1);
}

$book = $math->liveBookBase(BankAccount::query()->find($account->id), CarbonImmutable::parse('2099-05-31'));
$run = (new StartReconciliation(
    $math,
    app(NumberAllocator::class),
    app(AuditRecorder::class),
))->execute((int) $account->id, CarbonImmutable::parse('2099-05-31'), $book, 'verify: period close reconciliation', $owner->id);

$approve = app(ApproveReconciliation::class);
$approve->execute($run->load('bankAccount'), $owner->id);

$report = $validation->run('2099-05');
$cashRow = collect($report['checks'])->firstWhere('key', 'cash_reconciled');
check('the signed run turns the cash row green', $cashRow !== null && $cashRow['ok'] === true ? null : 'still red: '.($cashRow['detail'] ?? '?'));

echo "\nClose → refuse → reopen → close → lock (the machine)\n";
echo "--------------------------------------------------------\n";

$close = app(ClosePeriod::class);
$closed = $close->execute('2099-05', $owner->id);

check('the flip carries its instant and signer', $closed->status === 'closed' && $closed->closed_at !== null && $closed->closed_by === $owner->id ? null : 'closure columns incomplete');

refuses('a posting into the closed month is refused at the app door', fn () => $journal('20', 'verify-close-3'), PostingRejected::class);
check('the refused posting left no entry', JournalEntry::query()->where('source_event_id', 'verify-close-3')->count() === 0 ? null : 'an entry appeared in a closed month');

$reopen = app(ReopenPeriod::class);

refuses('a reopen without a sentence is refused', fn () => $reopen->execute('2099-05', '  ', $owner->id));
$reopened = $reopen->execute('2099-05', 'verify: proving the reopen path', $owner->id);
check('the reopen is deliberate and the month accepts postings again', $reopened->isOpen() ? null : 'still closed');
$journal('22', 'verify-close-4');
check('a reopened month takes the journal', JournalEntry::query()->where('source_event_id', 'verify-close-4')->exists() ? null : 'posting lost');

$close->execute('2099-05', $owner->id);
$lock = app(LockPeriod::class);
$locked = $lock->execute('2099-05', $owner->id);
check('lock follows close', $locked->isLocked() ? null : 'not locked');

refuses('a locked month cannot reopen', fn () => $reopen->execute('2099-05', 'verify: it should not work', $owner->id));
refuses('a locked month refuses postings at the app door too', fn () => $journal('25', 'verify-close-5'), PostingRejected::class);
refuses('locking twice says so', fn () => $lock->execute('2099-05', $owner->id));
refuses('closing an already locked month is refused', fn () => $close->execute('2099-05', $owner->id));

echo "\nCleanup\n-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', $owner->id, function () {
    $ids = JournalEntry::query()->where('source_type', 'verify_period_close')->pluck('id')->all();

    if ($ids !== []) {
        DB::table('gl_journal_lines')->whereIn('entry_id', $ids)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $ids)->delete();
    }

    $runIds = DB::table('reconciliations')->where('note', 'like', 'verify: period close%')->pluck('id')->all();

    if ($runIds !== []) {
        DB::table('reconciling_items')->whereIn('reconciliation_id', $runIds)->delete();
        DB::table('reconciliations')->whereIn('id', $runIds)->delete();
    }

    DB::table('operational_events')->where('aggregate_type', 'gl_period')->where('aggregate_id', '2099-05')->delete();
    DB::table('gl_periods')->where('period', '2099-05')->delete();
});

$left = JournalEntry::query()->where('source_type', 'verify_period_close')->count()
    + Period::query()->where('period', '2099-05')->count()
    + DB::table('reconciliations')->where('note', 'like', 'verify: period close%')->count();

$left === 0 ? ok('no verify rows left behind') : fail('leftover rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — PERIOD CLOSE OK' : ' — PERIOD CLOSE FAILED')."\n";

exit($failures === 0 ? 0 : 1);
