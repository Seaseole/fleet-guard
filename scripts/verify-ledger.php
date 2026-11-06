<?php

declare(strict_types=1);

use App\Domain\Kernel\Ledger\Actions\PostManualJournal;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\ImmutableLedgerException;
use App\Domain\Kernel\Ledger\Exceptions\PostingRejected;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\JournalLine;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Ledger\Support\LedgerVerifier;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 0 ledger verification.
 *
 * Not the test suite — tests are local-only by owner decision. This is the runnable proof
 * of the four layers: that a balanced journal posts, that an unbalanced one is refused at
 * every layer that claims to refuse it, and that `ledger:verify` can actually see drift.
 *
 * It cleans up after itself through LedgerBarrier::withLiftedGuard(), which is deliberate: the
 * emergency hatch is asserted in the same run that asserts immutability, so nobody can leave the
 * barrier row in place and still report a pass.
 *
 * Run: php scripts/verify-ledger.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

CurrentCompany::force(1);

foreach (app(NumberSequenceRegistry::class)->ensureAll() as $created) {
    echo "  sequence row created: {$created}\n";
}

$checks = 0;
$failures = 0;

function ok(string $what): void
{
    global $checks, $failures;
    $checks++;
    echo "  ok   {$what}\n";
}

function failed(string $what, string $why = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    echo '  FAIL '.$what.($why === '' ? '' : " — {$why}")."\n";
}

/** Assert the callable throws, and report which layer refused it. */
function refuses(string $what, callable $fn, string $expectClass = Throwable::class): void
{
    global $checks, $failures;
    $checks++;

    try {
        $fn();
        $failures++;
        echo "  FAIL {$what} — nothing refused it\n";
    } catch (Throwable $e) {
        if ($expectClass !== Throwable::class && ! $e instanceof $expectClass) {
            $failures++;
            echo '  FAIL '.$what.' — refused, but by '.class_basename($e).': '.$e->getMessage()."\n";

            return;
        }

        echo '  ok   '.$what.' — '.class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 78, '…')."\n";
    }
}

$accounts = Account::query()->pluck('id', 'code');
$ledger = app(LedgerService::class);
$user = (int) DB::table('users')->value('id');
$today = now()->startOfMonth()->addDays(5);

$money = fn (int $minor): Money => Money::ofMinor($minor, CurrentCompany::model()->base_currency_code);

echo "\nPosting a balanced manual journal\n";
echo "---------------------------------------------------\n";

$entry = $ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: October office rent',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5700'], Side::Debit, $money(450_000), note: 'rent'),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(450_000), note: 'from operating account'),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-rent-1',
    createdBy: $user,
));

$entry->isPosted()
    ? ok('the entry posted')
    : failed('the entry posted', $entry->status);
$entry->status === JournalEntry::Posted
    ? ok('status is posted')
    : failed('status is posted', $entry->status);

$entry->total_debit === 450_000 && $entry->total_credit === 450_000
    ? ok('totals are the line sums')
    : failed('totals are the line sums', "got {$entry->total_debit}/{$entry->total_credit}");

$entry->line_count === 2
    ? ok('line_count matches')
    : failed('line_count matches', (string) $entry->line_count);

str_starts_with($entry->entry_no, 'JV-')
    ? ok("entry_no came from the gap-free allocator ({$entry->entry_no})")
    : failed('entry_no came from the allocator', $entry->entry_no);

$entry->period_id !== null && Period::query()->find($entry->period_id)?->period === $today->format('Y-m')
    ? ok('the period row was resolved and created')
    : failed('period resolution', (string) $entry->period_id);

$entry->posted_on->toDateString() === $today->toDateString()
    ? ok('posted_on is the business date, not the run date')
    : failed('posted_on', $entry->posted_on->toDateString());

echo "\nRefusals — the layers under LedgerService\n";
echo "-------------------------------------------------\n";

refuses('app refuses an out-of-balance journal', fn () => $ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: unbalanced',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5700'], Side::Debit, $money(450_000)),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(400_000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-unbalanced',
    createdBy: $user,
)), PostingRejected::class);

refuses('DB CHECK refuses a posted unbalanced entry anyway', function () use ($entry, $today, $user, $accounts) {
    // Past every app guard, straight at the row: the CHECK is the layer that survives a bug in
    // the poster, so it is exercised by writing the entry as draft and flipping it by hand.
    $fake = JournalEntry::create([
        'id' => (string) Str::ulid(),
        'entry_no' => 'verify-CHECK-'.random_int(1000, 9999),
        'posted_at' => now(),
        'posted_on' => $today->toDateString(),
        'period_id' => $entry->period_id,
        'status' => JournalEntry::Draft,
        'description' => 'verify: forced imbalance',
        'currency_code' => CurrentCompany::model()->base_currency_code,
        'fx_rate' => '1',
        'fx_rate_source' => 'manual',
        'fx_rate_date' => $today->toDateString(),
        'base_currency_code' => CurrentCompany::model()->base_currency_code,
        'source_type' => 'verify_forced',
        'created_by' => $user,
    ]);

    JournalLine::create([
        'entry_id' => $fake->id,
        'account_id' => (int) $accounts['5700'],
        'direction' => 'debit',
        'amount_base' => 100,
        'posted_on' => $today->toDateString(),
        'currency_code' => CurrentCompany::model()->base_currency_code,
        'created_at' => now(),
    ]);

    $fake->status = JournalEntry::Posted;
    $fake->total_debit = 100;
    $fake->total_credit = 0;
    $fake->line_count = 1;
    $fake->save();
}, QueryException::class);

refuses('poster refuses a rollup header account', fn () => $ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: posting to a header',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5000'], Side::Debit, $money(1000)),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(1000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-header',
    createdBy: $user,
)), PostingRejected::class);

refuses('the dimension mask refuses a fuel line with no truck', function () use ($ledger, $accounts, $today, $user, $money) {
    $dto = PostingDto::inBaseCurrency(
        description: 'verify: fuel without a truck',
        postedOn: $today,
        baseCurrencyCode: CurrentCompany::model()->base_currency_code,
        lines: [
            new LineDto((int) $accounts['5100'], Side::Debit, $money(1000)),
            new LineDto((int) $accounts['1120'], Side::Credit, $money(1000)),
        ],
        sourceType: 'verify_ledger',
        sourceEventId: 'verify-mask',
        createdBy: $user,
    );

    $ledger->post($dto);
}, PostingRejected::class);

$fuelDto = PostingDto::inBaseCurrency(
    description: 'verify: fuel with its truck and trip',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5100'], Side::Debit, $money(120_000), dimensions: ['truck_id' => 1, 'trip_id' => '01TRIPNOTHINGMADEUP00']),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(120_000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-fuel-ok',
    createdBy: $user,
);

$fuel = $ledger->post($fuelDto);
$fuel->line_count === 2
    ? ok('the same line posts once the mask is satisfied')
    : failed('fuel posting with dimensions', $fuel->description ?? '');

$fuel->refresh()->lines()->where('truck_id', 1)->exists()
    ? ok('the typed dimension landed in its own column')
    : failed('typed dimension column', 'truck_id was not written');

echo "\nImmutability (layers 1 and 3)\n";
echo "------------------------------------\n";

refuses('the model refuses an update to a posted entry', function () use ($entry) {
    $entry->description = 'rewritten';
    $entry->save();
}, ImmutableLedgerException::class);

refuses('the model refuses a delete of a posted entry', fn () => $entry->delete(), ImmutableLedgerException::class);

refuses('a raw UPDATE on a line is refused by the trigger', fn () => DB::table('gl_journal_lines')
    ->where('entry_id', $entry->id)
    ->update(['amount_base' => 1]), QueryException::class);

refuses('a raw DELETE on an entry is refused by the trigger', fn () => DB::table('gl_journal_entries')
    ->where('id', $entry->id)
    ->delete(), QueryException::class);

$again = $ledger->findPosted(PostingDto::inBaseCurrency(
    description: 'verify: October office rent',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5700'], Side::Debit, $money(450_000)),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(450_000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-rent-1',
    createdBy: $user,
));

$again?->id === $entry->id
    ? ok('the idempotency lookup finds the entry the first delivery created')
    : failed('idempotency lookup');

$before = JournalEntry::query()->count();
$ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: October office rent (redelivered)',
    postedOn: $today,
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5700'], Side::Debit, $money(450_000)),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(450_000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-rent-1',
    createdBy: $user,
));
JournalEntry::query()->count() === $before
    ? ok('re-posting the same event created no second entry (ADR-0001 #3)')
    : failed('replay guard', 'a duplicate entry appeared');

echo "\nPeriod lock (layer 4)\n";
echo "---------------------------------\n";

// firstOrCreate, not create: this script must be re-runnable, and a run that died part-way (or a
// real back-dated close in production) may already own the 2099-01 row.
$lockedPeriod = Period::query()->firstOrCreate(
    ['period' => '2099-01'],
    ['starts_on' => '2099-01-01', 'ends_on' => '2099-01-31', 'status' => 'locked', 'closed_at' => now(), 'closed_by' => $user],
);

if (! $lockedPeriod->isLocked()) {
    LedgerBarrier::withLiftedGuard('verify: force the test period locked', null, function () use ($lockedPeriod) {
        DB::table('gl_periods')->where('id', $lockedPeriod->id)->update(['status' => 'locked', 'closed_at' => now()]);
    });
}

refuses('a posting into a locked period is refused by the app', fn () => $ledger->post(PostingDto::inBaseCurrency(
    description: 'verify: back-dated into a locked month',
    postedOn: CarbonImmutable::parse('2099-01-15'),
    baseCurrencyCode: CurrentCompany::model()->base_currency_code,
    lines: [
        new LineDto((int) $accounts['5700'], Side::Debit, $money(1000)),
        new LineDto((int) $accounts['1120'], Side::Credit, $money(1000)),
    ],
    sourceType: 'verify_ledger',
    sourceEventId: 'verify-locked',
    createdBy: $user,
)), PostingRejected::class);

refuses('…and by the trigger when the app is bypassed', function () use ($lockedPeriod, $user) {
    DB::table('gl_journal_entries')->insert([
        'id' => (string) Str::ulid(),
        'company_id' => 1,
        'entry_no' => 'verify-TRIGGER-'.random_int(1000, 9999),
        'posted_at' => now(),
        'posted_on' => '2099-01-15',
        'period_id' => $lockedPeriod->id,
        'status' => 'draft',
        'description' => 'verify: direct insert into a locked period',
        'currency_code' => 'BWP',
        'fx_rate' => '1',
        'fx_rate_source' => 'manual',
        'fx_rate_date' => '2099-01-15',
        'base_currency_code' => 'BWP',
        'source_type' => 'verify_forced',
        'created_by' => $user,
    ]);
}, QueryException::class);

echo "\nledger:verify catches the drift it claims to catch\n";
echo "---------------------------------------------------\n";

$verifier = app(LedgerVerifier::class);
$verifier->findings() === []
    ? ok('the sweep is clean over the entries this run posted')
    : failed('clean sweep', json_encode(array_column($verifier->findings(), 'finding')));

// Manufacture the one drift a CHECK cannot see: a header whose totals still balance each other
// but no longer match the lines underneath them. Asserting this also proves something worth
// recording — lifting the barrier disables the *triggers*, not the CHECKs, so even with the hatch
// open an unbalanced header stays impossible.
refuses('the balance CHECK survives even with the barrier lifted', function () use ($entry) {
    LedgerBarrier::withLiftedGuard('verify: try to unbalance a posted header', null, function () use ($entry) {
        DB::table('gl_journal_entries')->where('id', $entry->id)->update(['total_debit' => 999_999]);
    });
}, QueryException::class);

LedgerBarrier::withLiftedGuard('verify: manufacture header drift', $user, function () use ($entry, $verifier) {
    DB::table('gl_journal_entries')->where('id', $entry->id)->update([
        'total_debit' => 999_999,
        'total_credit' => 999_999,
    ]);

    // Sampled while the hatch is still open: finding #5 is about the barrier row itself, and by
    // the time this closure returns the guard is back on and there would be nothing to see.
    array_filter(
        $verifier->findings(),
        fn (array $f): bool => str_contains($f['finding'], 'immutability is currently OFF'),
    ) !== []
        ? ok('a raised barrier is itself reported as a finding')
        : failed('barrier-raised detection');
});

$drift = $verifier->findings();
$found = array_filter($drift, fn (array $f): bool => str_contains($f['finding'], 'total_debit'));
$found !== []
    ? ok('a header total that disagrees with its lines is reported')
    : failed('drift detection', json_encode(array_column($drift, 'finding')));

array_filter($drift, fn (array $f): bool => str_contains($f['finding'], 'immutability is currently OFF')) === []
    ? ok('the barrier finding is gone once the hatch is lowered again')
    : failed('barrier finding should have cleared', json_encode(array_column($drift, 'finding')));

LedgerBarrier::withLiftedGuard('verify: repair the drift', $user, fn () => DB::table('gl_journal_entries')
    ->where('id', $entry->id)
    ->update(['total_debit' => 450_000, 'total_credit' => 450_000]));

$verifier->findings() === []
    ? ok('the sweep is clean again after the repair')
    : failed('post-repair sweep');

LedgerBarrier::isLifted()
    ? failed('the barrier row was left in place')
    : ok('the barrier row was lowered again — immutability is back on');

echo "\nManual journal door (the screen-facing Action)\n";
echo "-----------------------------------------------\n";

$manual = app(PostManualJournal::class)->handle(
    description: 'verify: manual accrual',
    postedOn: $today,
    lines: [
        ['account_id' => (int) $accounts['5700'], 'direction' => 'debit', 'amount' => 25_000],
        ['account_id' => (int) $accounts['2110'], 'direction' => 'credit', 'amount' => 25_000],
    ],
    userId: $user,
);

$manual->isPosted()
    ? ok("a flat line array posts through the Action ({$manual->entry_no})")
    : failed('manual journal action');

refuses('…and the same door refuses a one-sided journal', fn () => app(PostManualJournal::class)->handle(
    description: 'verify: one sided',
    postedOn: $today,
    lines: [['account_id' => (int) $accounts['5700'], 'direction' => 'debit', 'amount' => 25_000]],
    userId: $user,
), PostingRejected::class);

refuses('…and refuses a major-unit float before it can be truncated', fn () => app(PostManualJournal::class)->handle(
    description: 'verify: float amount',
    postedOn: $today,
    lines: [
        ['account_id' => (int) $accounts['5700'], 'direction' => 'debit', 'amount' => 250.00],
        ['account_id' => (int) $accounts['2110'], 'direction' => 'credit', 'amount' => 250.00],
    ],
    userId: $user,
), PostingRejected::class);

// Two journals typed one after the other by a clerk are two documents. Each Action call mints its
// own source_event_id, so they must not collide on the idempotency unique index — which is what
// would happen if a manual journal reused '' and the second post silently returned the first.
$manualTwo = app(PostManualJournal::class)->handle(
    description: 'verify: second manual accrual',
    postedOn: $today,
    lines: [
        ['account_id' => (int) $accounts['5800'], 'direction' => 'debit', 'amount' => 1_200],
        ['account_id' => (int) $accounts['1120'], 'direction' => 'credit', 'amount' => 1_200],
    ],
    userId: $user,
);

$manualIds = JournalEntry::query()
    ->where('source_type', 'manual_journal')
    ->where('description', 'like', 'verify: %')
    ->count();

$manualIds === 2
    ? ok('two manual journals produced two entries, not one ('.$manualIds.')')
    : failed('manual journal event ids', (string) $manualIds);

$manual->id !== $manualTwo->id && $manual->entry_no !== $manualTwo->entry_no
    ? ok("and they carry distinct gap-free numbers ({$manual->entry_no}, {$manualTwo->entry_no})")
    : failed('manual journal numbers', $manual->entry_no.' / '.$manualTwo->entry_no);

echo "\nCleanup\n";
echo "-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', $user, function () {
    // Matched on description as well as source type: a real manual journal a person typed through
    // the screen shares the source_type, and a verification script has no business deleting it.
    $ids = JournalEntry::query()
        ->where(fn ($q) => $q->where('source_type', 'like', 'verify%')
            ->orWhere('entry_no', 'like', 'verify-%')
            ->orWhere('description', 'like', 'verify: %'))
        ->pluck('id')
        ->all();

    if ($ids !== []) {
        DB::table('gl_journal_lines')->whereIn('entry_id', $ids)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $ids)->delete();
    }

    Period::query()->where('period', '2099-01')->delete();
});

LedgerBarrier::isLifted()
    ? failed('cleanup left the barrier raised')
    : ok('cleanup ran with the barrier and lowered it again');

$left = JournalEntry::query()
    ->where(fn ($q) => $q->where('source_type', 'like', 'verify%')
        ->orWhere('entry_no', 'like', 'verify-%')
        ->orWhere('description', 'like', 'verify: %'))
    ->count();

$left === 0
    ? ok('no verify rows left behind ('.$left.' remain)')
    : failed('leftover verify rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — LEDGER OK' : ' — LEDGER FAILED')."\n";

exit($failures === 0 ? 0 : 1);
