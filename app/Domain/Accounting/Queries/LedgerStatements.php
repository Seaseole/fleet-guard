<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Data\BalanceSheet;
use App\Domain\Accounting\Data\BalanceSheetLine;
use App\Domain\Accounting\Data\BalanceSheetSection;
use App\Domain\Accounting\Data\CashFlow;
use App\Domain\Accounting\Data\CashFlowLine;
use App\Domain\Accounting\Data\CashFlowSection;
use App\Domain\Accounting\Data\ProfitAndLoss;
use App\Domain\Accounting\Data\ProfitAndLossLine;
use App\Domain\Accounting\Data\TrialBalance;
use App\Domain\Accounting\Data\TrialBalanceLine;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Support\CashFlowSections;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\AccountType;
use App\Domain\Kernel\Ledger\Data\ChartAccount;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonInterface;

/**
 * The ledger statements (financial statements read the ledger).
 *
 * Accounting asks the Kernel's sanctioned doors — {@see LedgerService::statementTotals()} for
 * the unnarrowed sums, {@see LedgerService::cashCounterpartyTotals()} for the money-in/money-out
 * view of the cash accounts, and {@see ChartOfAccounts::byCode()} for names, the statement axis
 * and the header tree — and re-shapes them into the four sheets an accountant signs: trial
 * balance, P&L, balance sheet, cash flow. No operational table is read here, which is the
 * point: no statement can drift from the journal because each one *is* the journal, read
 * through its doors.
 *
 * `amount_base` is authoritative (frozen at write time): every statement sums it and
 * shows nothing else, so a past window keeps the figures it was posted with no matter what the
 * rate does today.
 */
final class LedgerStatements
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Movement through the whole posted book up to the as-at date, per account, in the ledger's
     * own debit/credit columns. Opening balances posted by the cutover land in the same sums,
     * which is why the trial balance needs no separate "opening" parameter.
     */
    public function trialBalance(?CarbonInterface $asAt = null): TrialBalance
    {
        $names = [];

        foreach ($this->chart->byCode() as $code => $account) {
            if ($account->isPostable) {
                $names[$code] = $account->name;
            }
        }

        $debit = [];
        $credit = [];

        foreach ($this->ledger->statementTotals(null, $asAt) as $turnover) {
            if (! isset($names[$turnover->accountCode])) {
                continue;
            }

            if ($turnover->side === Side::Debit) {
                $debit[$turnover->accountCode] = ($debit[$turnover->accountCode] ?? 0) + $turnover->amountBase;
            } else {
                $credit[$turnover->accountCode] = ($credit[$turnover->accountCode] ?? 0) + $turnover->amountBase;
            }
        }

        $codes = array_map('strval', array_values(array_unique(array_merge(array_keys($debit), array_keys($credit)))));
        // PHP turns numeric-string array keys into integers on the way in; the chart speaks in
        // strings, so the codes come back as strings and sort lexicographically like the COA reads.
        sort($codes, SORT_STRING);

        $lines = array_map(
            static fn (string $code): TrialBalanceLine => new TrialBalanceLine(
                $code,
                $names[$code],
                $debit[$code] ?? 0,
                $credit[$code] ?? 0,
            ),
            $codes,
        );

        return new TrialBalance(
            asAt: $asAt?->toDateString() ?? 'all',
            currencyCode: CurrentCompany::model()->base_currency_code,
            lines: $lines,
        );
    }

    /**
     * Net movement on the profit-and-loss axis between two dates: revenue stated as earned
     * (credit positive), expenses as spent (debit positive). An account whose window nets to
     * zero — posted both ways and cancelled out — earns no row.
     */
    public function profitAndLoss(CarbonInterface $from, CarbonInterface $to): ProfitAndLoss
    {
        /** @var array<string, ChartAccount> $pl */
        $pl = [];

        foreach ($this->chart->byCode() as $code => $account) {
            if ($account->isPostable && $account->type->statement() === 'profit_and_loss') {
                $pl[$code] = $account;
            }
        }

        /** @var array<string, int> $net signed debit-positive movement per P&L account */
        $net = [];

        foreach ($this->ledger->statementTotals($from, $to) as $turnover) {
            if (isset($pl[$turnover->accountCode])) {
                $net[$turnover->accountCode] = ($net[$turnover->accountCode] ?? 0) + $turnover->signedDebit();
            }
        }

        $revenue = [];
        $expense = [];

        foreach ($net as $code => $signed) {
            if ($signed === 0) {
                continue;
            }

            $account = $pl[$code];

            // Debit-positive on a revenue account is a contra (a refund against revenue); the
            // sign rides with the account's type rather than hiding behind abs(), so the column
            // still adds up and the oddity stays visible.
            if ($account->type === AccountType::Revenue) {
                $revenue[$code] = new ProfitAndLossLine((string) $code, $account->name, -$signed);
            } else {
                $expense[$code] = new ProfitAndLossLine((string) $code, $account->name, $signed);
            }
        }

        ksort($revenue);
        ksort($expense);

        return new ProfitAndLoss(
            from: $from->toDateString(),
            to: $to->toDateString(),
            currencyCode: CurrentCompany::model()->base_currency_code,
            revenueLines: array_values($revenue),
            expenseLines: array_values($expense),
        );
    }

    /**
     * The balance sheet at an as-at date: cumulative movement on the balance-sheet axis,
     * grouped under the chart's own header rows, assets stated as held and liabilities/equity
     * as owed. The earnings of every not-yet-closed period ride on the equity side as one
     * named line (rolling them into 3020 is what a close does; until then the sheet
     * shows them unwrapped rather than pretending the equation balances without them).
     */
    public function balanceSheet(?CarbonInterface $asAt = null): BalanceSheet
    {
        $chart = $this->chart->byCode();

        /** @var array<string, int> signed amount per postable balance-sheet account */
        $signed = [];
        $earnings = 0;

        foreach ($this->ledger->statementTotals(null, $asAt) as $turnover) {
            $account = $chart[$turnover->accountCode] ?? null;

            if ($account === null || ! $account->isPostable) {
                continue;
            }

            $debit = $turnover->side === Side::Debit ? $turnover->amountBase : 0;
            $credit = $turnover->side === Side::Credit ? $turnover->amountBase : 0;

            if ($account->type->statement() === 'profit_and_loss') {
                $earnings += $credit - $debit;

                continue;
            }

            $held = $account->type === AccountType::Asset;
            $key = (string) $turnover->accountCode;
            $signed[$key] = ($signed[$key] ?? 0) + ($held ? $debit - $credit : $credit - $debit);
        }

        /** @var array<string, array<string, BalanceSheetLine>> heading => lines, chart order preserved */
        $grouped = [];

        foreach ($signed as $code => $amount) {
            if ($amount === 0) {
                continue;
            }

            // PHP turned the numeric-string keys of $signed into integers on the way in (the
            // trial-balance trap); the projection and the line speak strings.
            $code = (string) $code;
            $account = $chart[$code];
            $heading = $account->parentCode !== null && isset($chart[$account->parentCode])
                ? $chart[$account->parentCode]->name
                : $account->type->label();

            $grouped[$heading][$code] = new BalanceSheetLine($code, $account->name, $amount);
        }

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($grouped as $heading => $lines) {
            $section = new BalanceSheetSection($heading, array_values($lines));

            // Which side a section lands on comes from its accounts' type, so a header renamed by
            // an accountant still groups correctly — the type is the invariant, the name is not.
            $firstType = $chart[array_key_first($lines)]->type;

            match (true) {
                $firstType === AccountType::Asset => $assets[$heading] = $section,
                $firstType === AccountType::Liability => $liabilities[$heading] = $section,
                default => $equity[$heading] = $section,
            };
        }

        if ($earnings !== 0) {
            $equity['Earnings'] = new BalanceSheetSection('Earnings', [
                new BalanceSheetLine('—', 'Accumulated earnings of open periods', $earnings),
            ]);
        }

        return new BalanceSheet(
            asAt: $asAt?->toDateString() ?? 'all',
            currencyCode: CurrentCompany::model()->base_currency_code,
            assetSections: array_values($assets),
            liabilitySections: array_values($liabilities),
            equitySections: array_values($equity),
            accumulatedEarningsMinor: $earnings,
        );
    }

    /**
     * The cash-flow statement for one window, direct method: every posted entry that
     * touched one of the company's linked bank or cash accounts is attributed to the entry's
     * *other* lines — money in is a credit against the counterparty, money out is a debit —
     * and each counterparty is sorted into operating, investing or financing by where it hangs
     * on the chart tree ({@see CashFlowSections}).
     *
     * The cash accounts are the Bank & cash register's linked rows (BANK_ACCOUNT), the
     * same list the reconciliation screen will answer against: the register decides what counts
     * as cash, not a code list in this query. Transfers between two of them net to zero by
     * construction — the door excludes cash accounts from their own counterparty set.
     */
    public function cashFlow(CarbonInterface $from, CarbonInterface $to): CashFlow
    {
        $chart = $this->chart->byCode();
        $cashCodes = $this->cashAccountCodes($chart);
        $base = CurrentCompany::model()->base_currency_code;

        // A company with no linked ledger account in the Bank & cash register has no cash the
        // ledger can move — the honest sheet is an empty one, not an exception at reading time.
        if ($cashCodes === []) {
            return new CashFlow(
                from: $from->toDateString(),
                to: $to->toDateString(),
                currencyCode: $base,
                sections: [],
                openingCashMinor: 0,
                closingCashMinor: 0,
            );
        }

        $netOfCash = function (?CarbonInterface $until) use ($cashCodes): int {
            $total = 0;

            foreach ($this->ledger->statementTotals(null, $until) as $turnover) {
                if (in_array($turnover->accountCode, $cashCodes, true)) {
                    $total += $turnover->side === Side::Debit ? $turnover->amountBase : -$turnover->amountBase;
                }
            }

            return $total;
        };

        $opening = $netOfCash($from->copy()->subDay());
        $closing = $netOfCash($to);

        /** @var array<string, array{in: int, out: int}> per counterparty code */
        $movement = [];

        foreach ($this->ledger->cashCounterpartyTotals($cashCodes, $from, $to) as $turnover) {
            if (! isset($chart[$turnover->accountCode])) {
                continue;
            }

            $code = (string) $turnover->accountCode;

            if (! isset($movement[$code])) {
                $movement[$code] = ['in' => 0, 'out' => 0];
            }

            if ($turnover->side === Side::Credit) {
                $movement[$code]['in'] += $turnover->amountBase;
            } else {
                $movement[$code]['out'] += $turnover->amountBase;
            }
        }

        /** @var array<string, array<string, CashFlowLine>> section key => lines */
        $bySection = [];
        ksort($movement);

        foreach ($movement as $code => $amounts) {
            $code = (string) $code;
            $section = CashFlowSections::classify($chart[$code], $chart);
            $bySection[$section][$code] = new CashFlowLine($code, $chart[$code]->name, $amounts['in'], $amounts['out']);
        }

        $sections = [];

        foreach (CashFlowSections::order() as $key) {
            if (($bySection[$key] ?? []) === []) {
                continue;
            }

            $sections[] = new CashFlowSection($key, CashFlowSections::HEADINGS[$key], array_values($bySection[$key]));
        }

        return new CashFlow(
            from: $from->toDateString(),
            to: $to->toDateString(),
            currencyCode: $base,
            sections: $sections,
            openingCashMinor: $opening,
            closingCashMinor: $closing,
        );
    }

    /**
     * The chart codes the company's own cash lives under: every active Bank & cash register
     * row with a linked ledger account. An unlinked register row cannot appear in the ledger
     * yet, so it is not cash for the statement's purposes.
     *
     * @param  array<string, ChartAccount>  $chart
     * @return list<string>
     */
    private function cashAccountCodes(array $chart): array
    {
        $ids = BankAccount::query()
            ->where('active', true)
            ->whereNotNull('gl_account_id')
            ->pluck('gl_account_id')
            ->all();

        $byId = [];

        foreach ($chart as $account) {
            $byId[$account->id] = $account->code;
        }

        $codes = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $codes[] = (string) $byId[$id];
            }
        }

        return array_values(array_unique($codes));
    }
}
