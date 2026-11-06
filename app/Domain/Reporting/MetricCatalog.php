<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Data\Metric;

/**
 * The metric catalogue: every figure a report can print has a written
 * definition and the source query that answers it, in one list, next to the code that
 * implements it.
 *
 * This is the screen the owner opens when two people quote different numbers: the definition
 * settles what the figure *means*, the source line names the exact door it reads, and the
 * report keys say where it appears. A metric with no entry here has no business being on a
 * report — the catalogue is the gate as much as the glossary.
 */
final class MetricCatalog
{
    /** @var list<Metric>|null */
    private ?array $cached = null;

    /** @return list<Metric> */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        return $this->cached = [
            new Metric(
                key: 'jobs.awaiting_decision',
                label: 'Jobs waiting a decision',
                definition: 'Freight jobs in enquiry or quoted state right now — accepted, declined and closed jobs are out.',
                source: 'freight_jobs.status in (enquiry, quoted), company-scoped count',
                unit: 'count',
                reportKeys: ['daily_ops'],
            ),
            new Metric(
                key: 'trips.on_road',
                label: 'Trips on the road',
                definition: 'Trips whose status is departed, in transit, at border or arrived — committed to a vehicle and not yet closed.',
                source: 'trips.status in (departed, in_transit, at_border, arrived)',
                unit: 'count',
                reportKeys: ['daily_ops'],
            ),
            new Metric(
                key: 'trips.arrived',
                label: 'Trips arrived on the day',
                definition: 'Trips carrying an arrival timestamp on that calendar day, whatever the status moved to since.',
                source: 'trips.arrived_at on the day',
                unit: 'count',
                reportKeys: ['daily_ops'],
            ),
            new Metric(
                key: 'trips.pod_pending',
                label: 'Proof of delivery outstanding',
                definition: 'Trips in pod_pending state — arrived and signed-for is the register\'s own definition, not this report\'s.',
                source: 'trips.status = pod_pending',
                unit: 'count',
                reportKeys: ['daily_ops'],
            ),
            new Metric(
                key: 'trucks.workshop',
                label: 'Trucks in the workshop',
                definition: 'Trucks whose fleet status is in the workshop at the moment of reading — a live state, not the day\'s history.',
                source: 'trucks.status = in_workshop',
                unit: 'count',
                reportKeys: ['daily_ops'],
            ),
            new Metric(
                key: 'fuel.issued_litres',
                label: 'Fuel issued (litres)',
                definition: 'Litres on fuel issues recorded in the window, reversed issues excluded — the reversal is the answer to the wrong slip.',
                source: 'fuel_issues.litres_x100 where status != reversed, created_at in window',
                unit: 'litres',
                reportKeys: ['daily_ops', 'fuel_usage'],
            ),
            new Metric(
                key: 'fuel.issued_cost',
                label: 'Fuel issued (cost)',
                definition: 'The base-currency cost the issue rows carry, same population as the litres; never re-priced at reading time.',
                source: 'fuel_issues.cost_base where status != reversed, created_at in window',
                unit: 'money',
                reportKeys: ['daily_ops', 'fuel_usage'],
            ),
            new Metric(
                key: 'margin.earned',
                label: 'Earned (per truck)',
                definition: 'Chargeables ready or billed on trips that arrived in the window, grouped to the truck that ran them. Earned is earned invoiced or not; unbilled revenue is deliberately inside it.',
                source: 'chargeables.base_amount where status in (ready, billed), trip.arrived_at in window, grouped by trips.truck_id',
                unit: 'money',
                reportKeys: ['margin_truck', 'margin_route', 'margin_customer'],
            ),
            new Metric(
                key: 'margin.cost',
                label: 'Cost (per truck)',
                definition: 'Trip costs occurring in the window on the truck\'s trips — the operational rollup, not the ledger; the tie-out proves the two agree.',
                source: 'trip_costs.base_amount where occurred_on in window, grouped by trips.truck_id',
                unit: 'money',
                reportKeys: ['margin_truck', 'margin_route', 'margin_customer'],
            ),
            new Metric(
                key: 'margin.value',
                label: 'Margin',
                definition: 'Earned minus cost for the same window and the same truck. A negative figure is printed, not clamped.',
                source: 'derived: margin.earned − margin.cost',
                unit: 'money',
                reportKeys: ['margin_truck', 'margin_route', 'margin_customer'],
            ),
            new Metric(
                key: 'margin.pct',
                label: 'Margin %',
                definition: 'Margin over earned, in percent, one decimal; no revenue means no ratio, printed as an em-dash rather than 0%.',
                source: 'derived: margin / earned × 100 (earned > 0)',
                unit: 'percent',
                reportKeys: ['margin_truck', 'margin_route', 'margin_customer'],
            ),
            new Metric(
                key: 'ar.buckets',
                label: 'Receivables ageing',
                definition: 'Open balance per customer split by days past due (current, 1–30, 31–60, 61–90, 90+) — the bucket derived from due_on against the as-at date, never stored.',
                source: 'invoicing AgeingReport door over issued invoices with balance > 0',
                unit: 'money',
                reportKeys: ['ar_ageing'],
            ),
            new Metric(
                key: 'ar.oldest_due',
                label: 'Oldest due',
                definition: 'The oldest unpaid invoice\'s due date for the customer — the figure a chasing list sorts by.',
                source: 'invoicing AgeingReport door, per-customer oldest due_on',
                unit: 'date',
                reportKeys: ['ar_ageing'],
            ),
            new Metric(
                key: 'ar.collected',
                label: 'Collected in window',
                definition: 'Recorded (not voided) customer payments by their money date inside the window, in base minor units.',
                source: 'payments.base_amount where status = recorded and received_on in window',
                unit: 'money',
                reportKeys: ['ar_ageing'],
            ),
            new Metric(
                key: 'ledger.debits',
                label: 'Trial balance debits',
                definition: 'Every posted journal line up to the as-at date, summed per account in the ledger\'s own debit column; the credit column mirrors it and the two must agree.',
                source: 'LedgerStatements::trialBalance → LedgerService::statementTotals',
                unit: 'money',
                reportKeys: ['trial_balance'],
            ),
            new Metric(
                key: 'ledger.credits',
                label: 'Trial balance credits',
                definition: 'The same population as the debits, in the credit column.',
                source: 'LedgerStatements::trialBalance → LedgerService::statementTotals',
                unit: 'money',
                reportKeys: ['trial_balance'],
            ),
            new Metric(
                key: 'pl.revenue',
                label: 'Revenue (window)',
                definition: 'Net credit movement on accounts whose statement axis is profit-and-loss and whose type is revenue; a contra reduces it rather than disappearing.',
                source: 'LedgerStatements::profitAndLoss, revenue lines',
                unit: 'money',
                reportKeys: ['profit_loss'],
            ),
            new Metric(
                key: 'pl.expense',
                label: 'Expenses (window)',
                definition: 'Net debit movement on the profit-and-loss axis\'s expense accounts over the same window.',
                source: 'LedgerStatements::profitAndLoss, expense lines',
                unit: 'money',
                reportKeys: ['profit_loss'],
            ),
            new Metric(
                key: 'pl.net',
                label: 'Net profit (window)',
                definition: 'Revenue minus expenses as the P&L prints them — the ledger\'s answer, not a rollup of screens.',
                source: 'derived: pl.revenue − pl.expense',
                unit: 'money',
                reportKeys: ['profit_loss'],
            ),
            new Metric(
                key: 'bs.assets',
                label: 'Total assets',
                definition: 'Cumulative debit-positive movement on postable balance-sheet asset accounts through the as-at date, in base minor units; opening balances from the cutover are included because they posted as a real journal.',
                source: 'LedgerStatements::balanceSheet → LedgerService::statementTotals, asset sections',
                unit: 'money',
                reportKeys: ['balance_sheet'],
            ),
            new Metric(
                key: 'bs.liabilities',
                label: 'Total liabilities',
                definition: 'Cumulative credit-positive movement on postable liability accounts through the same as-at date.',
                source: 'LedgerStatements::balanceSheet → LedgerService::statementTotals, liability sections',
                unit: 'money',
                reportKeys: ['balance_sheet'],
            ),
            new Metric(
                key: 'bs.equity',
                label: 'Total equity',
                definition: 'Seeded equity accounts (owner capital, retained earnings) plus the accumulated earnings line below; the sheet\'s equation is assets − liabilities − this total, and the ledger can only print zero.',
                source: 'LedgerStatements::balanceSheet, equity sections',
                unit: 'money',
                reportKeys: ['balance_sheet'],
            ),
            new Metric(
                key: 'bs.earnings',
                label: 'Open-period earnings',
                definition: 'Cumulative net credit movement on the profit-and-loss axis through the as-at date — the profit of every period not yet closed, shown on the equity side until a close rolls it into 3020.',
                source: 'LedgerStatements::balanceSheet, earnings roll',
                unit: 'money',
                reportKeys: ['balance_sheet'],
            ),
            new Metric(
                key: 'cf.inflow',
                label: 'Cash in (window)',
                definition: 'Credits posted against counterparty accounts by entries that touched a linked bank or cash account in the window — receipts from customers and advances in, transfers between the fleet\'s own accounts excluded.',
                source: 'LedgerStatements::cashFlow → LedgerService::cashCounterpartyTotals, credit side',
                unit: 'money',
                reportKeys: ['cash_flow'],
            ),
            new Metric(
                key: 'cf.outflow',
                label: 'Cash out (window)',
                definition: 'The same population\'s debit side: supplier payments, fuel slips, wage and duty money that left a linked account.',
                source: 'LedgerStatements::cashFlow → LedgerService::cashCounterpartyTotals, debit side',
                unit: 'money',
                reportKeys: ['cash_flow'],
            ),
            new Metric(
                key: 'cf.net',
                label: 'Net cash movement (window)',
                definition: 'Inflow minus outflow across operating, investing and financing; must equal closing cash minus opening cash, and the sheet prints the disagreement in red when it does not.',
                source: 'derived: cf.inflow − cf.outflow',
                unit: 'money',
                reportKeys: ['cash_flow'],
            ),
            new Metric(
                key: 'cf.closing',
                label: 'Closing cash',
                definition: 'Cumulative debit minus credit on the linked bank and cash accounts through the window\'s end date — the same figure the bank register\'s balances reconcile against.',
                source: 'LedgerStatements::cashFlow → LedgerService::statementTotals over cash codes',
                unit: 'money',
                reportKeys: ['cash_flow'],
            ),
        ];
    }

    public function find(string $key): ?Metric
    {
        foreach ($this->all() as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    /** @return list<Metric> */
    public function forReport(string $reportKey): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Metric $m): bool => in_array($reportKey, $m->reportKeys, true),
        ));
    }
}
