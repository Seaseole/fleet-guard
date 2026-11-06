<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\BillLifecycle;
use App\Domain\Accounting\Data\CashForecast;
use App\Domain\Accounting\Data\CashForecastLine;
use App\Domain\Accounting\InstallmentStatus;
use App\Domain\Accounting\LoanLifecycle;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\Bill;
use App\Domain\Accounting\Models\Loan;
use App\Domain\Accounting\Models\LoanInstallment;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Ledger\ChartOfAccounts;
use App\Domain\Kernel\Ledger\Data\Side;
use App\Domain\Kernel\Ledger\LedgerService;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 13-week cash schedule roll-up (Phase 5: "13-week cash forecast from known
 * schedules"). Three sources the database already holds, and nothing else — this is a roll-up,
 * not a model:
 *
 *   in   — issued invoices still owed, arriving on their `due_on`
 *   out  — recorded bills still owed, leaving on their `due_on`
 *   out  — unpaid loan instalments, leaving on their plan rows (read from stored rows,
 *          never recomputed — the forecast asks the same rows the payment Action will post from)
 *
 * Opening cash is the live balance of the linked register accounts (the definition).
 * The honest assumption printed on the sheet: scheduled equals actual. A customer who pays late
 * moves the arrow, not the maths — the point of the row is the week the plan *says* the money
 * moves, so the forecast can be compared against reality week by week instead of being a wish.
 *
 * Invoice and Bill are other modules' models, read through nothing but Eloquent — D5 fences the
 * *ledger*, and the Reporting precedent (MarginByTruck reads Trip and Chargeable directly) is
 * the house practice for operational cross-reads.
 */
final class CashForecastQuery
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function build(CarbonInterface $from, int $weeks = 13): CashForecast
    {
        $start = CarbonImmutable::instance($from)->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addWeeks($weeks)->subDay();

        $opening = $this->cashOnHand($start->subDay());

        $in = $this->bucketize($start, $weeks, $this->invoiceReceipts());
        $out = $this->bucketize($start, $weeks, array_merge($this->billPayments(), $this->loanPayments()));

        $lines = [];
        $running = $opening;

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->addWeeks($i);
            $weekEnd = $weekStart->endOfWeek();
            $weekIn = $in[$i] ?? ['amount' => 0, 'keys' => []];
            $weekOut = $out[$i]['amount'] ?? 0;

            $running += $weekIn['amount'] - $weekOut;

            $lines[] = new CashForecastLine(
                weekStart: $weekStart->toDateString(),
                weekEnd: $weekEnd->toDateString(),
                expectedInMinor: $weekIn['amount'],
                expectedOutMinor: $weekOut,
                closingCashMinor: $running,
                inKeys: $weekIn['keys'],
            );
        }

        return new CashForecast(
            from: $start->toDateString(),
            to: $end->toDateString(),
            currencyCode: CurrentCompany::model()->base_currency_code,
            openingCashMinor: $opening,
            lines: $lines,
            assumptions: [
                'Items are expected to move on their scheduled date — invoices on due_on, bills on due_on, instalments on their plan rows.',
                'Opening cash is the ledger balance of the linked bank and cash accounts as at the sheet date.',
                'No modelled activity: a quiet week prints zero because the schedules say it is quiet.',
            ],
        );
    }

    /** Invoices the fleet is still owed: one expected receipt on each due date.
     *
     * @return list<array{on: CarbonInterface, amount: int, key: string}>
     */
    private function invoiceReceipts(): array
    {
        $rows = [];

        $invoices = Invoice::query()
            ->where('status', InvoiceLifecycle::Issued->value)
            ->where('balance_base', '>', 0)
            ->get(['due_on', 'number', 'balance_base']);

        foreach ($invoices as $invoice) {
            $rows[] = ['on' => $invoice->due_on, 'amount' => (int) $invoice->balance_base, 'key' => $invoice->number];
        }

        return $rows;
    }

    /** @return list<array{on: CarbonInterface, amount: int, key: string}>
     */
    private function billPayments(): array
    {
        $rows = [];

        $bills = Bill::query()
            ->where('status', BillLifecycle::Recorded->value)
            ->where('balance_base', '>', 0)
            ->get(['due_on', 'number', 'balance_base']);

        foreach ($bills as $bill) {
            $rows[] = ['on' => $bill->due_on, 'amount' => (int) $bill->balance_base, 'key' => $bill->number];
        }

        return $rows;
    }

    /** @return list<array{on: CarbonInterface, amount: int, key: string}>
     */
    private function loanPayments(): array
    {
        $rows = [];

        $loanIds = Loan::query()
            ->whereIn('status', [
                LoanLifecycle::Disbursed->value,
                LoanLifecycle::Servicing->value,
                LoanLifecycle::Arrears->value,
                LoanLifecycle::Restructured->value,
            ])
            ->pluck('id')
            ->all();

        if ($loanIds === []) {
            return $rows;
        }

        $instalments = LoanInstallment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', [
                InstallmentStatus::Scheduled->value,
                InstallmentStatus::Partial->value,
                InstallmentStatus::Overdue->value,
            ])
            ->get();

        foreach ($instalments as $instalment) {
            $rows[] = ['on' => $instalment->due_on, 'amount' => $instalment->outstandingMinor(), 'key' => "instalment {$instalment->seq}"];
        }

        return $rows;
    }

    /**
     * @param  list<array{on: CarbonInterface, amount: int, key: string}>  $rows
     * @return array<int, array{amount: int, keys: list<string>}> bucket index => totals
     */
    private function bucketize(CarbonImmutable $start, int $weeks, array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $index = (int) $start->diffInWeeks(CarbonImmutable::instance($row['on'])->startOfWeek(CarbonImmutable::MONDAY), false);

            if ($index < 0 || $index >= $weeks) {
                continue;
            }

            $buckets[$index]['amount'] = ($buckets[$index]['amount'] ?? 0) + $row['amount'];
            $buckets[$index]['keys'][] = $row['key'];
        }

        return array_map(
            static fn (array $b): array => ['amount' => (int) $b['amount'], 'keys' => array_values(array_unique($b['keys']))],
            $buckets,
        );
    }

    /** Live cash on hand: debit-minus-credit over the linked register accounts, through the ledger door. */
    private function cashOnHand(CarbonInterface $asAt): int
    {
        $chart = $this->chart->byCode();

        $codes = [];

        foreach (BankAccount::query()->where('active', true)->whereNotNull('gl_account_id')->pluck('gl_account_id') as $id) {
            foreach ($chart as $account) {
                if ($account->id === $id) {
                    $codes[(string) $account->code] = true;

                    break;
                }
            }
        }

        if ($codes === []) {
            return 0;
        }

        $total = 0;

        foreach ($this->ledger->statementTotals(null, $asAt) as $turnover) {
            if (isset($codes[$turnover->accountCode])) {
                $total += $turnover->side === Side::Debit ? $turnover->amountBase : -$turnover->amountBase;
            }
        }

        return $total;
    }
}
