<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\TrialBalance;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\AccountType;
use App\Domain\Kernel\Ledger\Data\LineDto;
use App\Domain\Kernel\Ledger\Data\PostingDto;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\Exceptions\ImmutableLedgerException;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Ledger\Models\Account;
use App\Domain\Kernel\Ledger\Models\JournalEntry;
use App\Domain\Kernel\Ledger\Models\JournalLine;
use App\Domain\Kernel\Ledger\Models\LedgerBarrier;
use App\Domain\Kernel\Ledger\Models\Period;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Numbering\NumberPeriod;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Actions\QueueReportExport;
use App\Domain\Reporting\Actions\UpsertScheduledReport;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\Exporters\ReportFileWriter;
use App\Domain\Reporting\Jobs\RunReportExport;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Mail\ScheduledReportMail;
use App\Domain\Reporting\MetricCatalog;
use App\Domain\Reporting\Models\ScheduledReport;
use App\Domain\Reporting\ReportDefinitions\MarginByTruck;
use App\Domain\Reporting\ReportExportFormat;
use App\Domain\Reporting\ReportExportStatus;
use App\Domain\Reporting\ReportsCatalogue;
use App\Domain\Security\Actions\ChangeUserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 8 reporting verification.
 *
 * Section A proves the two ledger statements (slice 8a): the trial balance and the P&L as pure
 * reads of the posted book, through the Kernel's doors, with the window deciding membership and
 * the statement axis — never a code-range guess — deciding what counts as profit-and-loss.
 *
 * Fixtures live in 2098 so the dev book's real October rows cannot contaminate a window; every
 * assertion is a *difference* between two reads, so a number the demo seed happens to carry can
 * never turn this harness red for a reason it did not cause.
 *
 * Run: php scripts/verify-reporting.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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

/** Assert the callable throws, and report which door refused it. */
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

/** The trial balance column for a code, treating an absent row as zero — differences are what fixtures prove. */
function column(TrialBalance $tb, string $code, string $side): int
{
    foreach ($tb->lines as $line) {
        if ($line->code === $code) {
            return $side === 'debit' ? $line->debitMinor : $line->creditMinor;
        }
    }

    return 0;
}

function cleanUp(): void
{
    if (! Schema::hasTable('gl_journal_entries')) {
        return;
    }

    // Section C's rows and files first-class: the disk entries belong to this harness, and a
    // probe user cannot leave while a security row names it (the FK teaches the order).
    if (Schema::hasTable('report_exports')) {
        // Safer than pattern-guessing: the probes own the only rows this harness ever queues.
        $probeIds = DB::table('users')->where('email', 'like', 'verify-rpt-%@fleetguard.local')->pluck('id')->all();
        $exportIds = DB::table('report_exports')->whereIn('actor_id', $probeIds ?: [0])->pluck('id')->all();

        foreach ($exportIds as $id) {
            $key = DB::table('report_exports')->where('id', $id)->value('storage_key');

            if (is_string($key) && Storage::disk(DocumentStore::Disk)->exists($key)) {
                Storage::disk(DocumentStore::Disk)->delete($key);
            }
        }

        if ($exportIds !== []) {
            DB::table('report_exports')->whereIn('id', $exportIds)->delete();
        }

        DB::table('scheduled_reports')->whereIn('set_by', $probeIds ?: [0])->delete();
        DB::table('security_events')->whereIn('user_id', $probeIds ?: [0])->delete();
        DB::table('model_has_roles')->where('model_type', 'App\Models\User')->whereIn('model_id', $probeIds ?: [0])->delete();
        DB::table('users')->whereIn('id', $probeIds ?: [0])->delete();
    }

    LedgerBarrier::withLiftedGuard('verify-reporting: remove this run\'s rows', null, function (): void {
        $ids = JournalEntry::query()
            ->where(fn ($q) => $q->where('source_type', 'verify_reporting')
                ->orWhere('entry_no', 'like', 'verify-rpt-%')
                ->orWhere('description', 'like', 'verify-rpt: %'))
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('gl_journal_lines')->whereIn('entry_id', $ids)->delete();
            DB::table('gl_journal_entries')->whereIn('id', $ids)->delete();
        }

        Period::query()->where('period', 'like', '2098-%')->delete();

        // The fiscal-period sequence rows this run minted for 2098. Nobody allocates journal
        // numbers for a year that has not happened, so the only rows that can carry these keys
        // are ours; numbering:ensure recreates any real period on demand.
        foreach (array_unique(array_map(
            static fn (string $on): string => NumberPeriod::keyFor(NumberPeriod::FinancialYear, CarbonImmutable::parse($on), 'journal_entry'),
            ['2098-03-10', '2098-04-05', '2098-05-05'],
        )) as $key) {
            DB::table('number_sequences')
                ->where('company_id', 1)
                ->where('scope', 'journal_entry')
                ->where('period_key', $key)
                ->delete();
        }
    });

    // Same closure shape as verify-invoicing: a harness must not break its neighbours' counters.
    $rows = DB::table('number_sequences')->where('company_id', 1)->where('scope', 'journal_entry')->get();

    foreach ($rows as $row) {
        $periodKey = (string) $row->period_key;

        $numbers = DB::table('gl_journal_entries')->where('company_id', 1)->whereNotNull('entry_no')
            ->when($periodKey !== '', static fn ($query) => $query->where('entry_no', 'like', '%'.$periodKey.'%'))
            ->pluck('entry_no');

        $highest = $numbers->map(static function (string $number): int {
            $dash = strrpos($number, '-');

            return (int) preg_replace('/\D/', '', $dash === false ? $number : substr($number, $dash));
        })->max() ?? 0;

        DB::table('number_sequences')->where('id', $row->id)
            ->update(['next_value' => $highest + 1, 'updated_at' => now()]);
    }
}

CurrentCompany::force(1);
cleanUp();
app(NumberSequenceRegistry::class)->ensureAll();

// The 2098 fixtures allocate into their own fiscal periods; the allocator refuses to mint rows
// mid-transaction (ADR-0002), so the harness creates them the way numbering:ensure would.
foreach (array_unique(array_map(
    static fn (string $on): string => NumberPeriod::keyFor(NumberPeriod::FinancialYear, CarbonImmutable::parse($on), 'journal_entry'),
    ['2098-03-10', '2098-04-05', '2098-05-05'],
)) as $periodKey) {
    DB::table('number_sequences')->insertOrIgnore([
        'company_id' => 1,
        'scope' => 'journal_entry',
        'period_key' => $periodKey,
        'next_value' => 1,
        'format' => config('fleet.numbering.scopes.journal_entry.format'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$accounts = Account::query()->pluck('id', 'code');
$ledger = app(LedgerService::class);
$statements = app(LedgerStatements::class);
$user = (int) DB::table('users')->value('id');
$base = CurrentCompany::model()->base_currency_code;

$money = static fn (int $minor): Money => Money::ofMinor($minor, $base);

$post = static function (string $event, string $on, array $lines) use ($ledger, $user, $base): JournalEntry {
    return $ledger->post(PostingDto::inBaseCurrency(
        description: "verify-rpt: {$event}",
        postedOn: CarbonImmutable::parse($on),
        baseCurrencyCode: $base,
        lines: $lines,
        sourceType: 'verify_reporting',
        sourceEventId: "verify-rpt-{$event}",
        createdBy: $user,
    ));
};

echo "\nA · Slice 8a — the trial balance and the P&L are reads of the posted book\n";
echo "-------------------------------------------------------------------------------\n";

// March 2098: revenue 3000.00 earned, a 500.00 contra against it, and a 700.00 expense that
// the month's 700.00 credit note cancels to a clean zero. April 2098: one 1000.00 expense.
$post('mar-rev', '2098-03-10', [
    new LineDto((int) $accounts['1120'], Side::Debit, $money(300_000)),
    new LineDto((int) $accounts['4900'], Side::Credit, $money(300_000)),
]);

$post('mar-contra', '2098-03-15', [
    new LineDto((int) $accounts['4900'], Side::Debit, $money(50_000)),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(50_000)),
]);

$post('mar-exp', '2098-03-20', [
    new LineDto((int) $accounts['5700'], Side::Debit, $money(70_000), note: 'verify-rpt pair'),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(70_000)),
]);

$post('mar-credit', '2098-03-25', [
    new LineDto((int) $accounts['5700'], Side::Credit, $money(70_000), note: 'verify-rpt pair'),
    new LineDto((int) $accounts['1120'], Side::Debit, $money(70_000)),
]);

$post('apr-exp', '2098-04-05', [
    new LineDto((int) $accounts['5700'], Side::Debit, $money(100_000)),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(100_000)),
]);

// A draft that will never post (an entry is not a fact until the flip) — it may not
// reach a statement. The trigger demands period_id point at a real row even for a draft, so the
// harness creates the month the resolver would have made, open and untouched.
$draftPeriod = Period::query()->firstOrCreate(
    ['period' => '2098-05'],
    ['starts_on' => '2098-05-01', 'ends_on' => '2098-05-31', 'status' => 'open'],
);

$draft = JournalEntry::create([
    'id' => (string) Str::ulid(),
    'entry_no' => 'verify-rpt-DRAFT',
    'posted_at' => now(),
    'posted_on' => '2098-05-05',
    'period_id' => $draftPeriod->id,
    'status' => JournalEntry::Draft,
    'description' => 'verify-rpt: draft never counted',
    'currency_code' => $base,
    'fx_rate' => '1',
    'fx_rate_source' => 'manual',
    'fx_rate_date' => '2098-05-05',
    'base_currency_code' => $base,
    'source_type' => 'verify_reporting',
    'created_by' => $user,
]);

JournalLine::create([
    'entry_id' => $draft->id,
    'account_id' => (int) $accounts['5700'],
    'direction' => 'debit',
    'amount_base' => 999_000,
    'posted_on' => '2098-05-05',
    'currency_code' => $base,
    'created_at' => now(),
]);

// The door boundary first: the rollup door still refuses what the statement door exists to allow.
refuses('totalsByAccount still refuses an unnarrowed sum', fn () => $ledger->totalsByAccount([]), InvalidArgumentException::class);

$turnover = $ledger->statementTotals(CarbonImmutable::parse('2098-03-01'), CarbonImmutable::parse('2098-03-31'));
$april = $ledger->statementTotals(CarbonImmutable::parse('2098-04-01'), CarbonImmutable::parse('2098-04-30'));

check($turnover !== [], 'statementTotals answers for the March window', 'it came back empty');
check(
    array_values(array_filter($april, static fn ($t): bool => $t->accountCode === '4900')) === [],
    '…and March revenue does not leak into April',
);

// P&L: axis membership, contra sign honesty, zero-net exclusion, window isolation.
$march = $statements->profitAndLoss(CarbonImmutable::parse('2098-03-01'), CarbonImmutable::parse('2098-03-31'));

check(count($march->revenueLines) === 1 && $march->revenueLines[0]->code === '4900', 'March P&L names exactly the revenue account that moved', json_encode($march->revenueLines));
check($march->revenueLines[0]->amountMinor === 250_000, 'the contra rides inside revenue: 3000.00 earned minus 500.00 refunded', (string) ($march->revenueLines[0]->amountMinor ?? null));
check($march->expenseLines === [], 'an expense that nets to zero earns no row', json_encode($march->expenseLines));
check($march->netProfitMinor() === 250_000, 'March net profit is the ledger\'s March answer', (string) $march->netProfitMinor());

$aprilSheet = $statements->profitAndLoss(CarbonImmutable::parse('2098-04-01'), CarbonImmutable::parse('2098-04-30'));

check($aprilSheet->revenueLines === [], 'April earned nothing on the March journal', json_encode($aprilSheet->revenueLines));
check(count($aprilSheet->expenseLines) === 1 && $aprilSheet->expenseLines[0]->amountMinor === 100_000, 'April spent 1000.00 and says so', json_encode($aprilSheet->expenseLines));
check($aprilSheet->netProfitMinor() === -100_000, 'a month with only spend prints a negative net, not a clamped one', (string) $aprilSheet->netProfitMinor());

// Trial balance: as-at isolation by *difference*, so the dev book's own figures cannot contaminate.
$feb = $statements->trialBalance(CarbonImmutable::parse('2098-02-28'));
$marTb = $statements->trialBalance(CarbonImmutable::parse('2098-03-31'));
$aprTb = $statements->trialBalance(CarbonImmutable::parse('2098-04-30'));
$mayTb = $statements->trialBalance(CarbonImmutable::parse('2098-05-31'));

check(column($marTb, '4900', 'credit') - column($feb, '4900', 'credit') === 300_000, 'the trial balance credit column grew by exactly what March earned');
check(column($marTb, '4900', 'debit') - column($feb, '4900', 'debit') === 50_000, '…and the debit column by exactly the contra');
check(column($marTb, '5700', 'debit') - column($feb, '5700', 'debit') === 70_000, 'March expense shows as movement in both the ledger\'s columns, not as a net');
check(column($aprTb, '5700', 'debit') - column($marTb, '5700', 'debit') === 100_000, 'April\'s expense arrives in April\'s column');
check(column($mayTb, '5700', 'debit') - column($aprTb, '5700', 'debit') === 0, 'the draft entry never reaches the trial balance', (string) (column($mayTb, '5700', 'debit') - column($aprTb, '5700', 'debit')));

$full = $statements->trialBalance();

check($full->isBalanced(), 'the whole posted book balances — every entry was balanced at the door', sprintf('debits %d vs credits %d', $full->totalDebitMinor(), $full->totalCreditMinor()));

$codes = array_map(static fn ($l): string => $l->code, $full->lines);
$sorted = $codes;
sort($sorted);
check($codes === $sorted, 'lines arrive sorted by account code', implode(',', array_slice($codes, 0, 8)));
check(array_filter($full->lines, static fn ($l): bool => $l->debitMinor === 0 && $l->creditMinor === 0) === [], 'no line moved in neither direction', '');

// The axis is the chart's fact, not the sheet's guess.
$chart = app(ChartOfAccounts::class)->byCode();
check($chart['4900']->type === AccountType::Revenue, 'the chart projects 4900 as Revenue');
check($chart['4900']->type->statement() === 'profit_and_loss', '…and its statement axis is profit-and-loss');
check($chart['1120']->type->statement() === 'balance_sheet', 'the bank\'s axis is the balance sheet, so it never appears on a P&L');

echo "\nB · Slice 8b — the catalogues and the six builders\n";
echo "------------------------------------------------------\n";

$catalogue = app(ReportsCatalogue::class);
$metrics = app(MetricCatalog::class);

$reports = $catalogue->all();
check(count($reports) === 6, 'the library holds the six Phase 8 reports', (string) count($reports));

$orphanKeys = [];

foreach ($reports as $report) {
    foreach ($report->metricKeys as $key) {
        if ($metrics->find($key) === null) {
            $orphanKeys[] = $report->key.':'.$key;
        }
    }
}

check($orphanKeys === [], 'every metric a report prints has a written definition', implode(' ', $orphanKeys));

$unshown = [];

foreach ($metrics->all() as $metric) {
    foreach ($metric->reportKeys as $reportKey) {
        if ($catalogue->find($reportKey) === null) {
            $unshown[] = $metric->key.'→'.$reportKey;
        }
    }
}

check($unshown === [], 'every metric names reports that exist', implode(' ', $unshown));

$stale = [];

foreach ($reports as $report) {
    foreach ($metrics->forReport($report->key) as $metric) {
        if (! in_array($metric->key, $report->metricKeys, true)) {
            $stale[] = $metric->key;
        }
    }
}

check($stale === [], 'the two halves of the index agree in both directions', implode(' ', $stale));

check($catalogue->find('margin_truck')->viewGate === 'seeTripMargin', 'the margin report is gated by the field door');
check($catalogue->find('trial_balance')->exportAbility === 'reports.export.financial', '…and financial reports export through the ⚑ ability');

$day = CarbonImmutable::parse('2020-01-01');

foreach ($reports as $report) {
    $data = app($report->builder)->build($day, $day);

    $width = count($data->columns);
    $ragged = array_filter($data->rows, static fn (array $row): bool => count($row) !== $width);

    check($ragged === [], $report->key.' builds a table whose rows all fit its columns', count($ragged).' ragged rows');
    check($data->window !== '', $report->key.' names its own window');
}

$daily = app($reports[0]->builder)->build($day, $day);

$fuelRow = array_values(array_filter($daily->rows, static fn (array $r): bool => str_starts_with($r[0], 'Fuel issued on')));
check(count($fuelRow) === 1 && $fuelRow[0][1] === '0.00', 'a day that predates the seed answers with honest zeros', json_encode($fuelRow));

$margins = app(MarginByTruck::class)->build(CarbonImmutable::parse('2098-01-01'), CarbonImmutable::parse('2098-01-31'));
check($margins->isEmpty(), 'a window no truck drove in prints no rows', json_encode($margins->rows));

echo "\nC · Slice 8c — the export door, the writers, and the download gates\n";
echo "---------------------------------------------------------------------\n";

$acctProbe = User::query()->firstOrNew(['email' => 'verify-rpt-accountant@fleetguard.local']);
$acctProbe->name = 'Verify RPT Accountant';
$acctProbe->company_id = 1;
$acctProbe->password = bcrypt('verify-only-not-a-secret');
$acctProbe->email_verified_at = now();
$acctProbe->save();
CurrentCompany::force(1);
app(ChangeUserRole::class)($acctProbe->fresh(), RoleKey::Accountant);

$dispProbe = User::query()->firstOrNew(['email' => 'verify-rpt-dispatcher@fleetguard.local']);
$dispProbe->name = 'Verify RPT Dispatcher';
$dispProbe->company_id = 1;
$dispProbe->password = bcrypt('verify-only-not-a-secret');
$dispProbe->email_verified_at = now();
$dispProbe->save();
app(ChangeUserRole::class)($dispProbe->fresh(), RoleKey::Dispatcher);

Queue::fake();

Auth::login($acctProbe->fresh());

refuses('an unknown report key is refused before a row is written', function () use ($acctProbe): void {
    app(QueueReportExport::class)->handle('no_such', ReportExportFormat::Csv, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'), $acctProbe);
}, InvalidArgumentException::class);

$export = app(QueueReportExport::class)->handle('trial_balance', ReportExportFormat::Csv, CarbonImmutable::parse('2098-06-01'), CarbonImmutable::parse('2098-06-30'), $acctProbe);

check($export->status === ReportExportStatus::Queued, 'the accountant queued a trial-balance CSV');
check(
    DB::table('security_events')->where('kind', 'export_queued')->where('user_id', $acctProbe->id)->exists(),
    'the queue step wrote its own security row',
);
$pushed = false;

try {
    Queue::assertPushed(RunReportExport::class, static fn (RunReportExport $job, string $queue): bool => $queue === 'exports');
    $pushed = true;
} catch (Throwable) {
    // reported below — an assertion that dies outside a test run would kill the whole sweep
}

check($pushed, 'the job went onto the named exports queue');

Auth::login($dispProbe->fresh());

refuses('the dispatcher cannot even ask for a financial export', function () use ($dispProbe): void {
    app(QueueReportExport::class)->handle('trial_balance', ReportExportFormat::Csv, CarbonImmutable::parse('2098-06-01'), CarbonImmutable::parse('2098-06-30'), $dispProbe);
}, InvalidArgumentException::class);

$ops = app(QueueReportExport::class)->handle('daily_ops', ReportExportFormat::Pdf, CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-01'), $dispProbe->fresh());
check($ops->status === ReportExportStatus::Queued, '…but the ops report is the dispatcher\'s to export ("ops only")');

Auth::logout();
CurrentCompany::force(1);

// Run the job bodies directly — the fake queue held them, the worker on Cloud will not exist here.
$writer = app(ReportFileWriter::class);

foreach ([$export, $ops] as $row) {
    (new RunReportExport($row->id, $row->company_id))->handle(app(ReportsCatalogue::class), $writer);

    $row->refresh();

    check($row->status === ReportExportStatus::Ready, $row->report.'.'.$row->format->value.' finished ready', (string) $row->failure_reason);
    check(
        $row->storage_key !== null && Storage::disk(DocumentStore::Disk)->exists($row->storage_key),
        '…and the bytes landed on the private disk',
    );

    $body = (string) Storage::disk(DocumentStore::Disk)->get($row->storage_key);
    $head = substr(str_replace("\xEF\xBB\xBF", '', $body), 0, 7);
    $magic = match ($row->format) {
        ReportExportFormat::Csv => 'Account',
        ReportExportFormat::Pdf => '%PDF',
        ReportExportFormat::Xlsx => 'PK',
    };
    check(str_starts_with($head, $magic), $row->report.'.'.$row->format->value.' bytes look like the right kind of file', $head);
}

$march = app(QueueReportExport::class);
CurrentCompany::force(1);
Auth::login($acctProbe->fresh());
$xlsx = $march->handle('profit_loss', ReportExportFormat::Xlsx, CarbonImmutable::parse('2098-06-01'), CarbonImmutable::parse('2098-06-30'), $acctProbe);
(new RunReportExport($xlsx->id, $xlsx->company_id))->handle(app(ReportsCatalogue::class), $writer);
$xlsx->refresh();
$xlsxHead = substr((string) Storage::disk(DocumentStore::Disk)->get((string) $xlsx->storage_key), 0, 2);
check($xlsx->status === ReportExportStatus::Ready, 'the xlsx export finished ready', (string) $xlsx->failure_reason);
check($xlsxHead === 'PK', '…and the workbook is a real zip container', $xlsxHead);

// Download gates: only the queueing seat, re-asked at read time.
$other = $dispProbe->fresh();
check(
    ! Gate::forUser($other)->allows('download', $export),
    'a second seat cannot download the accountant\'s export (not a side door)',
);
check(
    Gate::forUser($acctProbe->fresh())->allows('download', $export),
    '…while the seat that queued it can',
);

echo "\nD · The 12-month DoD: a wide window and a synthetic 20 000-row sheet complete\n";
echo "-----------------------------------------------------------------------------\n";

$wide = app(ReportsCatalogue::class)->find('trial_balance');
$wideData = app($wide->builder)->build(CarbonImmutable::parse('2025-11-01'), CarbonImmutable::parse('2026-11-01'));
check(count($wideData->columns) === 4 && $wideData->totals !== null, 'the twelve-month trial balance built without error', '');

$bigRows = [];

for ($i = 0; $i < 20_000; $i++) {
    $bigRows[] = ['ACCT-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), number_format($i / 7, 2)];
}

$big = new ReportData(
    title: 'Synthetic',
    window: 'stress',
    columns: [['label' => 'Account', 'numeric' => false], ['label' => 'Value', 'numeric' => true]],
    rows: $bigRows,
    totals: ['Total', '0.00'],
);

$peakBefore = memory_get_usage();
$written = $writer->write($big, ReportExportFormat::Xlsx);
$grew = memory_get_peak_usage(true) - memory_get_usage(true);

check(str_contains($written['bytes'], "\x50\x4B") && strlen($written['bytes']) > 100_000, '20 000 rows came out as a real workbook', (string) strlen($written['bytes']));
check($grew < 64 * 1024 * 1024, '…under 64 MB of peak growth (the memory rule)', (string) round($grew / 1048576, 1).' MB');

echo "\nE · Slice 8d — the nominations, the due test, and the mail body\n";
echo "---------------------------------------------------------------\n";

$upsert = app(UpsertScheduledReport::class);

Auth::login($acctProbe->fresh());
CurrentCompany::force(1);

refuses('a report that is not nominated-for-scheduling is refused by name', fn () => $upsert->handle('trial_balance', 'daily', null, null, $acctProbe->fresh()), InvalidArgumentException::class);
refuses('a fake frequency is refused before the CHECK ever has to be', fn () => $upsert->handle('daily_ops', 'hourly', null, null, $acctProbe->fresh()), InvalidArgumentException::class);
refuses('weekly without a day is refused', fn () => $upsert->handle('daily_ops', 'weekly', null, null, $acctProbe->fresh()), InvalidArgumentException::class);
refuses('day 29 of a monthly is refused — February has no such promise', fn () => $upsert->handle('daily_ops', 'monthly', null, 29, $acctProbe->fresh()), InvalidArgumentException::class);

Auth::login($dispProbe->fresh());
refuses('a dispatcher cannot schedule a report it cannot read', fn () => $upsert->handle('margin_truck', 'weekly', 1, null, $dispProbe->fresh()), InvalidArgumentException::class);

Auth::login($acctProbe->fresh());
$row = $upsert->handle('margin_truck', 'weekly', 1, null, $acctProbe->fresh());
check($row->frequency === 'weekly' && (int) $row->day_of_week === 1, 'the accountant nominates the weekly margin mail');

$row->refresh();
$again = $upsert->handle('margin_truck', 'monthly', null, 5, $acctProbe->fresh());
check($again->id === $row->id && $again->frequency === 'monthly', '…and re-nominating updates the one row (the unique key holds)');

$again->refresh();
check($again->isDueOn(Carbon::parse('2026-11-03')->startOfDay()) === false, 'a Tuesday is not the 5th', '');
check($again->isDueOn(Carbon::parse('2026-11-05')->startOfDay()) === true, 'the 5th is the day');

// The daily board is an ops report — its nomination belongs to a seat holding
// `reports.export.ops`, which the Accountant deliberately does not.
Auth::login($dispProbe->fresh());
$dailyRow = $upsert->handle('daily_ops', 'daily', null, null, $dispProbe->fresh());
Auth::login($acctProbe->fresh());
check($dailyRow->isDueOn(Carbon::parse('2026-11-02')->startOfDay()) === true, 'a daily answers due every day');

$window = $dailyRow->windowFor(Carbon::parse('2026-11-02')->startOfDay());
check($window[0]->toDateString() === '2026-11-01' && $window[1]->toDateString() === '2026-11-01', '…and its window is yesterday, both ends');

$monthly = $again->windowFor(Carbon::parse('2026-02-05')->startOfDay());
check($monthly[0]->toDateString() === '2026-01-01' && $monthly[1]->toDateString() === '2026-01-31', 'a February 5th monthly answers the whole of January');

Queue::fake();

Artisan::call('reports:run-scheduled', ['--company' => 1, '--date' => '2026-11-05']);

$pushed = 0;
foreach (Queue::pushedJobs() as $jobs) {
    foreach ($jobs as $entry) {
        if ($entry['job'] instanceof SendScheduledReport) {
            $pushed++;
            check($entry['job']->queue === 'reports', '…on the named reports queue', (string) $entry['job']->queue);
        }
    }
}

check($pushed === 2, 'the 5th dispatches the daily board and the monthly margin, and keeps the weekly silent', (string) $pushed);

// The mail body itself: build, PDF, queue the mailable — no real transport.
Mail::fake();

$job = new SendScheduledReport($again->id, 1);
$job->handle(app(ReportsCatalogue::class), app(ReportFileWriter::class));

$again->refresh();
check($again->last_sent_at !== null, 'the send stamped the row');

// Mail::fake records queueable mailables in its own queue list — that is where the proof lives,
// not in the queue fake (the mailer was swapped wholesale).
$mailQueued = false;

try {
    Mail::assertQueued(
        ScheduledReportMail::class,
        static fn ($mail): bool => $mail->reportTitle === 'Margin per truck' && $mail->pdf !== '' && str_contains($mail->window, '→'),
    );
    $mailQueued = true;
} catch (Throwable) {
}

check($mailQueued, 'the mail reached the seats holding the export ability, with the whole sheet attached');

Mail::fake();
Artisan::call('reports:run-scheduled', ['--company' => 1, '--date' => '2026-11-02']);
$upsert->disable('margin_truck');
check(ScheduledReport::query()->where('report', 'margin_truck')->first()->enabled === false, 'stop disables without deleting the history');

echo "\nF · Slice 8f — the tie-out names the difference and rings the bell\n";
echo "---------------------------------------------------------------------------\n";

// The alert's notification rides the database queue; a verification run must not leave a
// SendQueuedNotifications row behind for someone's worker to mail at 03:00.
Queue::fake();

CurrentCompany::force(1);

$tieTrip = DB::table('trips')
    ->whereExists(static fn ($q) => $q->selectRaw('1')->from('trip_costs')->whereColumn('trip_costs.trip_id', 'trips.id'))
    ->orderBy('trip_no')
    ->first(['id', 'trip_no']);

if ($tieTrip === null) {
    failed('a dev trip carries costs, so the tie-out has something to reconcile');
} else {
    $tieStartedAt = now()->subSecond();

    $code = Artisan::call('reports:tie-to-ledger', ['--company' => 1, '--trip' => $tieTrip->id]);
    $out = Artisan::output();
    CurrentCompany::force(1);

    check($code === 0 && str_contains($out, 'tie, per trip'), 'a clean run exits 0 and says the book ties', trim($out));

    // Drift the operational side only. The ledger cannot be moved here — immutability is the
    // point — which is exactly the asymmetry the tie-out exists to detect.
    $cost = DB::table('trip_costs')->where('trip_id', $tieTrip->id)->orderBy('id')->first(['id', 'base_amount']);
    $raised = null;

    DB::table('trip_costs')->where('id', $cost->id)->update(['base_amount' => (int) $cost->base_amount + 1000]);

    try {
        $code = Artisan::call('reports:tie-to-ledger', ['--company' => 1, '--trip' => $tieTrip->id]);
        $out = Artisan::output();
        CurrentCompany::force(1);

        check($code === 1, 'a drifted trip exits 1, so the scheduler hears it', (string) $code);
        check(
            str_contains($out, 'DRIFT') && str_contains($out, (string) $tieTrip->trip_no) && str_contains($out, 'trip_costs hold'),
            '…and the line names the trip and holds both figures against each other',
            trim($out),
        );

        /** @var object|null $raised */
        $raised = DB::table('alerts')
            ->where('type', 'self_check_failure')
            ->where('cooldown_key', 'like', '%reports:tie-to-ledger%')
            ->whereNull('resolved_at')
            ->orderByDesc('first_seen_at')
            ->first(['id', 'first_seen_at', 'payload']);

        check($raised!== null, 'the difference reached the bell, not just stdout');

        $payload = $raised === null ? [] : json_decode((string) $raised->payload, true);

        check(
            is_array($payload)
                && ($payload['check'] ?? null) === 'reports:tie-to-ledger'
                && str_contains((string) ($payload['findings'][0] ?? ''), (string) $tieTrip->trip_no),
            'the alert carries the finding the owner will read',
        );
    } finally {
        DB::table('trip_costs')->where('id', $cost->id)->update(['base_amount' => (int) $cost->base_amount]);
        CurrentCompany::force(1);
    }

    $code = Artisan::call('reports:tie-to-ledger', ['--company' => 1, '--trip' => $tieTrip->id]);
    CurrentCompany::force(1);

    $stillLive = DB::table('alerts')
        ->where('type', 'self_check_failure')
        ->where('cooldown_key', 'like', '%reports:tie-to-ledger%')
        ->whereNull('resolved_at')
        ->exists();

    check($code === 0 &&! $stillLive, 'the repaired trip clears the exit and resolves the alert — not wallpaper');

    // Remove the alert this section raised, and the trail that describes it. A resolved row is
    // history the dev book did not earn; the check above has already proven it existed.
    if ($raised !== null && Carbon::parse((string) $raised->first_seen_at)->gte($tieStartedAt)) {
        DB::table('operational_events')
            ->where('aggregate_type', 'alert')
            ->where('aggregate_id', (string) $raised->id)
            ->delete();
        DB::table('alerts')->where('id', $raised->id)->delete();
    }
}

echo "\nG · Cleanup\n";
echo "-----------\n";

$leftFixtures = JournalEntry::query()
    ->where(fn ($q) => $q->where('source_type', 'verify_reporting')->orWhere('entry_no', 'like', 'verify-rpt-%'))
    ->count();

// A posted entry cannot be deleted through the model, which the cleanup above answers with
// the barrier — this check proves the barrier path actually ran, not that nothing was ever written.
check($leftFixtures === 6, 'the five posted fixtures and the draft still stand before cleanup ('.$leftFixtures.' rows)', '');

refuses('the model refuses to delete a fixture posted entry', function (): void {
    JournalEntry::query()->where('source_type', 'verify_reporting')->where('status', JournalEntry::Posted)->firstOrFail()->delete();
}, ImmutableLedgerException::class);

cleanUp();

$left = JournalEntry::query()
    ->where(fn ($q) => $q->where('source_type', 'verify_reporting')->orWhere('entry_no', 'like', 'verify-rpt-%'))
    ->count();

check($left === 0, 'no verify-rpt rows left behind', (string) $left);
check(! LedgerBarrier::isLifted(), 'cleanup lowered the barrier again');
check(Period::query()->where('period', 'like', '2098-%')->count() === 0, 'the 2098 periods this run created are gone');

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — REPORTING OK' : ' — REPORTING FAILED')."\n";

exit($failures === 0 ? 0 : 1);
