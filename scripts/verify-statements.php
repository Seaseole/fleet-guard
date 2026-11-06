<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateBankAccount;
use App\Domain\Accounting\Data\BankAccountDto;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Queries\LedgerStatements;
use App\Domain\Accounting\Support\LedgerAccountLink;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
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
use App\Domain\Reporting\MetricCatalog;
use App\Domain\Reporting\ReportDefinitions\LedgerBalanceSheet;
use App\Domain\Reporting\ReportDefinitions\LedgerCashFlow;
use App\Domain\Reporting\ReportsCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 slice — balance sheet and cash-flow statement verification (Phase 5's two
 * unbuilt sheets, "financial statements read the ledger").
 *
 * Not the test suite — tests are local-only by owner decision. This is the runnable
 * proof that the two sheets are the journal: five hand-posted journals in an untouched future
 * month (income in, overhead out, a truck bought, a petty-cash transfer, a loan drawn) must
 * show exactly those movements on the cash-flow statement, keep the balance-sheet equation at
 * zero, leave the internal transfer invisible, and roll the open-period earnings by exactly
 * the income-minus-expense of the window.
 *
 * Run: php scripts/verify-statements.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    echo "REFUSED: this harness writes ledger rows. It runs against dev only.\n";
    exit(2);
}

CurrentCompany::force(1);

// Allocators never insert their own rows; ensure the registry's scopes — including the
// fiscal periods this harness posts into — before the first journal.
foreach (app(NumberSequenceRegistry::class)->ensureAll(CarbonImmutable::parse('2099-03-15')) as $created) {
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

function check(string $what, ?string $why = null): void
{
    $why === null ? ok($what) : failed($what, $why);
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

$ledger = app(LedgerService::class);
$statements = app(LedgerStatements::class);
$base = CurrentCompany::model()->base_currency_code;
$money = fn (int $minor): Money => Money::ofMinor($minor, $base);
$accounts = Account::query()->pluck('id', 'code');

echo "Setup — a linked cash register and a clean future month\n";
echo "-------------------------------------------------------\n";

$createdBankIds = [];

foreach ([['Verify statements bank', '1120', 'bank'], ['Verify statements purse', '1110', 'cash']] as [$name, $code, $type]) {
    $existing = BankAccount::query()->where('gl_account_id', $accounts[$code])->where('active', true)->exists();

    if (! $existing) {
        $row = (new CreateBankAccount)(new BankAccountDto(
            name: $name,
            institution: 'Verification Bank',
            currencyCode: $base,
            type: $type,
            glAccountId: (int) $accounts[$code],
        ));
        $createdBankIds[] = $row->id;
        ok("register row '{$name}' opened and linked to {$code}");
    } else {
        ok("register row for {$code} already existed — reused");
    }
}

// The statements read the register's links; prove the door the register uses.
$codes = [];

foreach (app(ChartOfAccounts::class)->byCode() as $code => $account) {
    if ($account->id === (int) $accounts['1120'] || $account->id === (int) $accounts['1110']) {
        $codes[] = (string) $code;
    }
}

check(
    'the register links are visible to the statements',
    count($codes) === 2 ? null : 'cash codes resolved to '.json_encode($codes),
);

echo "\nPosting the window (the shapes, one future month)\n";
echo "----------------------------------------------------\n";

$post = function (string $day, string $description, array $lines, string $eventId) use ($ledger, $base): void {
    $ledger->post(PostingDto::inBaseCurrency(
        description: "verify: statements {$description}",
        postedOn: CarbonImmutable::parse("2099-03-{$day}"),
        baseCurrencyCode: $base,
        lines: $lines,
        sourceType: 'verify_statements',
        sourceEventId: $eventId,
    ));
};

$post('05', 'customer receipt', [
    new LineDto((int) $accounts['1120'], Side::Debit, $money(100_000), note: 'received'),
    new LineDto((int) $accounts['4900'], Side::Credit, $money(100_000), note: 'other income'),
], 'verify-statements-income');

$post('06', 'overhead paid', [
    new LineDto((int) $accounts['5700'], Side::Debit, $money(40_000), note: 'paid'),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(40_000), note: 'operating account'),
], 'verify-statements-overhead');

$post('07', 'truck acquired', [
    new LineDto((int) $accounts['1210'], Side::Debit, $money(500_000), note: 'cost'),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(500_000), note: 'paid from bank'),
], 'verify-statements-truck');

$post('08', 'cash pulled to the purse', [
    new LineDto((int) $accounts['1110'], Side::Debit, $money(5_000), note: 'to petty cash'),
    new LineDto((int) $accounts['1120'], Side::Credit, $money(5_000), note: 'from bank'),
], 'verify-statements-transfer');

$post('09', 'loan drawn', [
    new LineDto((int) $accounts['1120'], Side::Debit, $money(200_000), note: 'facility'),
    new LineDto((int) $accounts['2210'], Side::Credit, $money(200_000), note: 'vehicle finance'),
], 'verify-statements-loan');

check('five journals posted', JournalEntry::query()->where('source_type', 'verify_statements')->count() === 5 ? null : 'not all five landed');

$from = CarbonImmutable::parse('2099-03-01');
$to = CarbonImmutable::parse('2099-03-31');

echo "\nThe cash-flow statement (direct method)\n";
echo "---------------------------------------------\n";

$cf = $statements->cashFlow($from, $to);

check('the window ties out: opening + section nets = closing', $cf->isConsistent() ? null : "opening {$cf->openingCashMinor} + net {$cf->netChangeMinor()} ≠ closing {$cf->closingCashMinor}");
check('net change is exactly the five journals', $cf->netChangeMinor() === 100_000 - 40_000 - 500_000 + 200_000 ? null : "got {$cf->netChangeMinor()}");

$operating = $cf->section('operating');
$investing = $cf->section('investing');
$financing = $cf->section('financing');

check(
    'operating nets the income minus the overhead',
    $operating !== null && $operating->netMinor() === 60_000 ? null : 'operating section missing or wrong: '.($operating?->netMinor() ?? 'none'),
);
check(
    'the truck is investing, at −5000.00',
    $investing !== null && $investing->netMinor() === -500_000 ? null : 'investing: '.($investing?->netMinor() ?? 'none'),
);
check(
    'the loan draw is financing, at +2000.00',
    $financing !== null && $financing->netMinor() === 200_000 ? null : 'financing: '.($financing?->netMinor() ?? 'none'),
);

$allCodes = [];

foreach ($cf->sections as $section) {
    foreach ($section->lines as $line) {
        $allCodes[] = $line->code;
    }
}

check(
    'the internal transfer is invisible — cash never counterposes cash',
    ! in_array('1110', $allCodes, true) && ! in_array('1120', $allCodes, true) ? null : 'a cash account appeared as its own counterparty: '.json_encode($allCodes),
);

echo "\nThe balance sheet (cumulative, with the open-period earnings)\n";
echo "-------------------------------------------------------------------\n";

$before = $statements->balanceSheet(CarbonImmutable::parse('2099-02-28'));
$after = $statements->balanceSheet($to);

check('the sheet balances at the window end', $after->isBalanced() ? null : "out by {$after->differenceMinor()}");
check('the sheet was balanced before the window too', $before->isBalanced() ? null : "out by {$before->differenceMinor()}");
check(
    'assets moved by the truck net of the cash that paid for it',
    $after->totalAssetsMinor() - $before->totalAssetsMinor() === 260_000 ? null : 'asset delta '.($after->totalAssetsMinor() - $before->totalAssetsMinor()),
);
check(
    'liabilities moved by exactly the loan drawn',
    $after->totalLiabilitiesMinor() - $before->totalLiabilitiesMinor() === 200_000 ? null : 'liability delta '.($after->totalLiabilitiesMinor() - $before->totalLiabilitiesMinor()),
);
check(
    'open-period earnings moved by income minus overhead (until a close, they ride the equity side)',
    $after->accumulatedEarningsMinor - $before->accumulatedEarningsMinor === 60_000 ? null : 'earnings delta '.($after->accumulatedEarningsMinor - $before->accumulatedEarningsMinor),
);

echo "\nThe gates around the doors\n";
echo "---------------------------------------\n";

refuses('the counterparty door refuses to guess what cash is', fn () => $ledger->cashCounterpartyTotals([], $from, $to));
refuses('a header account cannot be a cash link', fn () => app(LedgerAccountLink::class)->assertLinkable((int) $accounts['1100']));
refuses('a revenue account cannot be a cash link', fn () => app(LedgerAccountLink::class)->assertLinkable((int) $accounts['4900']));
refuses(
    'a linked account with posted movement cannot be re-pointed',
    fn () => app(LedgerAccountLink::class)->assertChangeSafe(
        BankAccount::query()->where('gl_account_id', $accounts['1120'])->where('active', true)->firstOrFail(),
        (int) $accounts['1110'],
    ),
);

echo "\nThe report library sees the same sheets (Phase 8's one-door rule)\n";
echo "------------------------------------------------------------------------\n";

$catalogue = app(ReportsCatalogue::class);
check('the catalogue carries balance_sheet', $catalogue->find('balance_sheet') !== null ? null : 'missing');
check('the catalogue carries cash_flow', $catalogue->find('cash_flow') !== null ? null : 'missing');

$metrics = app(MetricCatalog::class);
check('cash_flow has its four metric definitions', count($metrics->forReport('cash_flow')) === 4 ? null : 'got '.count($metrics->forReport('cash_flow')));
check('balance_sheet has its four metric definitions', count($metrics->forReport('balance_sheet')) === 4 ? null : 'got '.count($metrics->forReport('balance_sheet')));

$cfReport = (new LedgerCashFlow($statements))->build($from, $to);
check('the cash-flow export ties out', str_contains((string) $cfReport->note, 'Ties out') ? null : "note: {$cfReport->note}");

$bsReport = (new LedgerBalanceSheet($statements))->build($from, $to);
check('the balance-sheet export balances', str_contains((string) $bsReport->note, 'Balanced') ? null : "note: {$bsReport->note}");

echo "\nCleanup\n-------\n";

LedgerBarrier::withLiftedGuard('verify: remove this run\'s rows', null, function () use ($createdBankIds) {
    $ids = JournalEntry::query()
        ->where('source_type', 'verify_statements')
        ->orWhere('description', 'like', 'verify: statements %')
        ->pluck('id')
        ->all();

    if ($ids !== []) {
        DB::table('gl_journal_lines')->whereIn('entry_id', $ids)->delete();
        DB::table('gl_journal_entries')->whereIn('id', $ids)->delete();
    }

    if ($createdBankIds !== []) {
        DB::table('bank_accounts')->whereIn('id', $createdBankIds)->delete();
    }

    Period::query()->where('period', '2099-03')->delete();
});

LedgerBarrier::isLifted()
    ? failed('cleanup left the barrier raised')
    : ok('cleanup ran with the barrier and lowered it again');

$left = JournalEntry::query()->where('source_type', 'verify_statements')->count();

$left === 0
    ? ok('no verify rows left behind')
    : failed('leftover verify rows', (string) $left);

echo "\n{$checks} checks, {$failures} failed".($failures === 0 ? ' — STATEMENTS OK' : ' — STATEMENTS FAILED')."\n";

exit($failures === 0 ? 0 : 1);
