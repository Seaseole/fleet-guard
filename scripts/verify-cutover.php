<?php

declare(strict_types=1);

use App\Domain\Kernel\Cutover\Actions\ImportCutoverBatch;
use App\Domain\Kernel\Cutover\Actions\PostOpeningTrialBalance;
use App\Domain\Kernel\Cutover\Actions\ReverseOpeningTrialBalance;
use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverErrorReport;
use App\Domain\Kernel\Identity\PermissionCatalogue;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Ledger\Support\LedgerVerifier;
use App\Domain\Kernel\Numbering\NumberPeriod;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Phase 0.5 cutover verification (DESIGN.md §15.2).
 *
 * Not the test suite — `tests/` is local-only by owner decision (§14.6). This is the runnable proof
 * that a balanced trial balance validates, posts as one real journal, is refused when re-uploaded,
 * reverses through a mirror entry, and that the error report names every line the rehearsal refuses.
 *
 * It writes to the development database and removes everything it created — including the journals,
 * through the ledger's own emergency hatch, which is asserted lowered again before the script
 * reports.
 *
 * Run: php scripts/verify-cutover.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

CurrentCompany::force(1);

$sequenceRows = [];

foreach (app(NumberSequenceRegistry::class)->ensureAll() as $created) {
    echo "  sequence row created: {$created}\n";
    $sequenceRows[] = $created;
}

// The rehearsal posts into 2031-04, which is FY31/32 — a fiscal year no seeded row covers yet.
foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse('2031-04-01')) as $created) {
    echo "  sequence row created: {$created}\n";
    $sequenceRows[] = $created;
}

/**
 * Earlier verification runs rewind `number_sequences` wholesale (verify-kernel deletes the table),
 * which can leave a journal counter below a number that is still in use. That is a numbering
 * finding, not a cutover one, so the run lifts this counter back above the highest issued number
 * before it posts — otherwise it fails on a duplicate `entry_no` that says nothing about cutover.
 */
function liftJournalCounter(CarbonImmutable $on): void
{
    $companyId = CurrentCompany::id();
    $periodKey = NumberPeriod::keyFor('financial_year', $on, 'journal_entry');

    $row = DB::table('number_sequences')
        ->where('company_id', $companyId)
        ->where('scope', 'journal_entry')
        ->where('period_key', $periodKey)
        ->first();

    if ($row === null) {
        return;
    }

    $highest = DB::table('gl_journal_entries')
        ->where('company_id', $companyId)
        ->where('entry_no', 'like', "JV-{$periodKey}-%")
        ->pluck('entry_no')
        ->map(static fn (string $number): int => (int) substr($number, -6))
        ->max() ?? 0;

    if ($highest >= (int) $row->next_value) {
        DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $highest + 1, 'updated_at' => now()]);
        echo "  journal counter for {$periodKey} lifted to ".($highest + 1)."\n";
    }
}

liftJournalCounter(CarbonImmutable::parse('2031-04-01'));
liftJournalCounter(CarbonImmutable::now());

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

function check(bool $condition, string $what, string $why = ''): void
{
    $condition ? ok($what) : failed($what, $why);
}

function refuses(string $what, callable $fn, string $expectClass): void
{
    try {
        $fn();
        failed($what, "expected {$expectClass}, nothing was thrown");
    } catch (Throwable $e) {
        $e instanceof $expectClass
            ? ok($what.' — '.str_replace("\n", ' ', substr($e->getMessage(), 0, 110)))
            : failed($what, 'expected '.$expectClass.', got '.$e::class.': '.$e->getMessage());
    }
}

$user = User::query()->orderBy('id')->first();
$temporaryUser = false;

if ($user === null) {
    $user = User::forceCreate([
        'name' => 'Cutover verifier',
        'email' => Str::uuid().'@fleetguard.local',
        'password' => Str::random(40),
        'company_id' => 1,
        'email_verified_at' => now(),
    ]);
    $temporaryUser = true;
}

$userId = (int) $user->id;
$import = app(ImportCutoverBatch::class);
$post = app(PostOpeningTrialBalance::class);
$reverse = app(ReverseOpeningTrialBalance::class);

$batchIds = [];
$publicIds = [];
$entryIds = [];

try {
    echo "\nPermissions (§9.3, §15.2)\n";

    $accountant = Role::query()->where('company_id', 1)->where('name', 'accountant')->firstOrFail();
    $owner = Role::query()->where('company_id', 1)->where('name', 'owner')->firstOrFail();
    $accountantGrants = $accountant->permissions->pluck('name')->all();
    $ownerGrants = $owner->permissions->pluck('name')->all();

    check(in_array('cutover.import', $accountantGrants, true), 'the accountant may validate and preview');
    check(! in_array('cutover.post', $accountantGrants, true), 'the accountant may not post');
    check(in_array('cutover.import', $ownerGrants, true) && in_array('cutover.post', $ownerGrants, true), 'the owner may do both');
    check(PermissionCatalogue::requiresReauth('cutover.post'), 'posting is a re-auth ability');
    check(! PermissionCatalogue::requiresReauth('cutover.import'), 'validating is not a re-auth ability');
    check(PermissionCatalogue::isFinancial('cutover.import'), 'importing a trial balance counts as financial data');

    echo "\nA balanced file validates and posts\n";

    $balanced = <<<'CSV'
account_code,account_name,debit,credit,description
1120,BWP bank,"125 000.00",,Opening bank
1210,Trucks and trailers,"800 000.00",,Fleet at cost
1290,Accumulated depreciation,,"210 000.00",Fleet depreciation
2210,Vehicle finance loans,,"420 000.00",
3010,Owner capital,,"295 000.00",Opening equity
CSV;

    $batch = $import->handle(CutoverDataset::OpeningTrialBalance, $balanced, 'opening-tb.csv', $userId);
    $batchIds[] = $batch->id;
    $publicIds[] = $batch->public_id;

    check($batch->status === CutoverBatchStatus::Validated, 'a balanced file is validated');
    check($batch->row_count === 5 && $batch->valid_row_count === 5, 'all five rows are valid');
    check($batch->total_debit === 92_500_000 && $batch->total_credit === 92_500_000, 'totals are stored in minor units');
    check(CutoverErrorReport::build($batch) === "line,account_code,account_name,debit,credit,description,errors\n", 'the error report is header-only for a clean file');

    $posted = $post->handle($batch, CarbonImmutable::parse('2031-04-01'), $userId);
    $entryIds[] = $posted->journal_entry_id;

    check($posted->status === CutoverBatchStatus::Posted, 'the batch is posted');
    check($posted->journal_entry_id !== null, 'the batch names its journal entry');

    $entry = DB::table('gl_journal_entries')->where('id', $posted->journal_entry_id)->first();

    check($entry !== null, 'the journal entry exists');
    check($entry->source_type === 'opening_balance', 'the entry is sourced as an opening balance');
    check($entry->source_id === $batch->public_id, 'the entry carries the batch public id');
    check($entry->posted_on === '2031-04-01', 'the entry posts on the chosen business date');
    check((int) $entry->total_debit === 92_500_000 && (int) $entry->total_credit === 92_500_000, 'debits equal credits on the entry');
    check((int) $entry->line_count === 5, 'one line per row');

    $findings = array_filter(
        app(LedgerVerifier::class)->findings(),
        fn (array $finding): bool => in_array($finding['entry'], $entryIds, true),
    );
    check($findings === [], 'ledger:verify finds no drift in the posted entry');

    $postedEvent = DB::table('operational_events')
        ->where('aggregate_type', 'cutover_batch')
        ->where('aggregate_id', $batch->public_id)
        ->where('to_state', 'posted')
        ->first();
    check($postedEvent !== null, 'the posted transition is on the operational trail');

    echo "\nThe same file cannot be committed twice\n";

    $duplicate = $import->handle(CutoverDataset::OpeningTrialBalance, $balanced, 'opening-tb-copy.csv', $userId);
    $batchIds[] = $duplicate->id;
    $publicIds[] = $duplicate->public_id;

    check($duplicate->status === CutoverBatchStatus::Rejected, 'the second upload is rejected');
    check(
        str_contains(implode(' ', $duplicate->errors()), 'already posted'),
        'the rejection names the batch that already holds these balances',
    );

    echo "\nAn unbalanced file is rejected as a batch\n";

    $unbalanced = <<<'CSV'
account_code,account_name,debit,credit
1120,BWP bank,"100 000.00",
3010,Owner capital,,"90 000.00"
CSV;

    $bad = $import->handle(CutoverDataset::OpeningTrialBalance, $unbalanced, 'unbalanced.csv', $userId);
    $batchIds[] = $bad->id;
    $publicIds[] = $bad->public_id;

    check($bad->status === CutoverBatchStatus::Rejected, 'the unbalanced file is rejected');
    check($bad->error_row_count === 0, 'both rows are individually valid');
    check(str_contains(implode(' ', $bad->errors()), 'does not balance'), 'the batch error says the file does not balance');

    echo "\nRow errors are specific and downloadable\n";

    $messy = <<<'CSV'
account_code,account_name,debit,credit
9999,Ghost account,"10.00",
1120,BWP bank,"20.00",
1120,BWP bank,"30.00",
1100,Current assets,"40.00",
1150,Accounts receivable,"50.00",
1210,Trucks,100.00,"60.00"
3010,Owner capital,,"150.00"
CSV;

    $messyBatch = $import->handle(CutoverDataset::OpeningTrialBalance, $messy, 'messy.csv', $userId);
    $batchIds[] = $messyBatch->id;
    $publicIds[] = $messyBatch->public_id;

    $report = CutoverErrorReport::build($messyBatch);

    check($messyBatch->status === CutoverBatchStatus::Rejected, 'the messy file is rejected');
    check($messyBatch->row_count === 7 && $messyBatch->valid_row_count === 2 && $messyBatch->error_row_count === 5, 'five rows fail and two are valid');
    check(str_contains($report, 'not in the chart of accounts'), 'an unknown code is named in the report');
    check(str_contains($report, 'already appears on line 3'), 'a duplicate account names the earlier line');
    check(str_contains($report, 'header account'), 'a header account is refused with its reason');
    check(str_contains($report, 'requires customer detail'), 'a dimensioned account explains why it cannot post yet');
    check(str_contains($report, 'not both'), 'a row with both sides is refused');
    check(str_contains($report, 'does not balance'), 'the batch-level balance error is in the report');
    check(substr_count($report, "\n") === 7, 'the report has a header, one batch error and five error rows', (string) substr_count($report, "\n"));

    echo "\nA posted batch reverses instead of being edited\n";

    $reversed = $reverse->handle($posted, 'rehearsal rollback', $userId);
    $entryIds[] = $reversed->reversal_entry_id;

    check($reversed->status === CutoverBatchStatus::Reversed, 'the batch is reversed');
    check($reversed->reversal_entry_id !== null, 'the batch names its reversal entry');

    $mirror = DB::table('gl_journal_entries')->where('id', $reversed->reversal_entry_id)->first();
    $firstLine = DB::table('gl_journal_lines')
        ->where('entry_id', $reversed->reversal_entry_id)
        ->orderBy('id')
        ->first();

    check($mirror->source_type === 'opening_balance_reversal', 'the mirror uses the reversal source type');
    check($mirror->reverses_entry_id === $posted->journal_entry_id, 'the mirror points at the original entry');
    check($mirror->netting_group === $batch->public_id, 'the mirror carries the batch netting group');
    check($firstLine->direction === 'credit' && (int) $firstLine->amount_base === 12_500_000, 'the mirror swaps the first line to the credit side');
    check((int) $mirror->total_debit === (int) $mirror->total_credit, 'the mirror balances');

    $reversedEvent = DB::table('operational_events')
        ->where('aggregate_type', 'cutover_batch')
        ->where('aggregate_id', $batch->public_id)
        ->where('to_state', 'reversed')
        ->first();
    check($reversedEvent !== null && $reversedEvent->reason === 'rehearsal rollback', 'the reversal reason is on the operational trail');

    refuses('a reversed batch cannot be reversed again', fn () => $reverse->handle($reversed, 'again', $userId), CutoverRejected::class);
    refuses('a reversed batch cannot be re-posted', fn () => $post->handle($reversed, CarbonImmutable::parse('2031-04-01'), $userId), CutoverRejected::class);
    refuses('a reversal needs a reason', function () use ($post, $reverse, $userId, &$batchIds, &$publicIds, &$entryIds): void {
        // A new posted batch is needed because this one is already reversed.
        $fresh = app(ImportCutoverBatch::class)->handle(
            CutoverDataset::OpeningTrialBalance,
            "account_code,debit,credit\n1120,10.00,\n3010,,10.00\n",
            'reason.csv',
            $userId,
        );
        $batchIds[] = $fresh->id;
        $publicIds[] = $fresh->public_id;

        $postedAgain = $post->handle($fresh, CarbonImmutable::parse('2031-04-01'), $userId);
        $entryIds[] = $postedAgain->journal_entry_id;

        $reverse->handle($postedAgain, '   ', $userId);
    }, CutoverRejected::class);

    echo "\nDatabase guards hold the shape too (§6.3)\n";

    refuses('the row CHECK refuses a valid row with both sides', function () use ($batch): void {
        DB::table('cutover_rows')->insert([
            'company_id' => 1,
            'cutover_batch_id' => $batch->id,
            'line_number' => 900,
            'account_code' => '1120',
            'debit_minor' => 100,
            'credit_minor' => 100,
            'status' => 'valid',
            'errors' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, QueryException::class);
} finally {
    DB::table('cutover_batches')->whereIn('id', $batchIds)->delete();

    $entryIds = array_values(array_filter($entryIds));

    if ($entryIds !== []) {
        LedgerBarrier::withLiftedGuard('verify-cutover: remove this run\'s entries', $userId, function () use ($entryIds): void {
            DB::table('gl_journal_lines')->whereIn('entry_id', $entryIds)->delete();

            // A reversal row points at its original through `reverses_entry_id`, so the children go
            // first — the self-referencing FK refuses to orphan or reorder them.
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->whereNotNull('reverses_entry_id')->delete();
            DB::table('gl_journal_entries')->whereIn('id', $entryIds)->delete();
        });
    }

    if ($publicIds !== []) {
        DB::table('operational_events')
            ->where('aggregate_type', 'cutover_batch')
            ->whereIn('aggregate_id', $publicIds)
            ->delete();
    }

    DB::table('audits')
        ->where('auditable_type', CutoverBatch::class)
        ->whereIn('auditable_id', array_map('strval', $batchIds))
        ->delete();

    DB::table('gl_periods')->where('period', '2031-04')->delete();

    foreach ($sequenceRows as $pair) {
        [$scope, $periodKey] = explode(':', $pair, 2);
        DB::table('number_sequences')
            ->where('company_id', 1)
            ->where('scope', $scope)
            ->where('period_key', $periodKey)
            ->delete();
    }

    if ($temporaryUser) {
        DB::table('users')->where('id', $userId)->delete();
    }

    LedgerBarrier::isLifted()
        ? failed('the barrier row was left in place')
        : ok('the barrier row is lowered — ledger immutability is back on');
}

echo "\n".($failures === 0 ? 'PASS' : 'FAIL')." — {$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
