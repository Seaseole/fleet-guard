<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Data\ReportDefinition;

/**
 * The report library's registry. Every consumer — the screen, the export queue, the
 * scheduled mail — lists and validates through this catalogue, so a report that exists in one
 * place and not another is a bug this shape cannot have.
 *
 * The gates are the matrix and the field doors restated here once: `seeTripMargin` guards
 * the margin columns because a margin per truck is exactly the figure a dispatcher must not
 * read; the financial exports ask `reports.export.financial`, which the PermissionCatalogue
 * marks ⚑ re-auth, because an export is an authorised read, not a side door.
 */
final class ReportsCatalogue
{
    /** @var list<ReportDefinition>|null */
    private ?array $cached = null;

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        return $this->cached = [
            new ReportDefinition(
                key: 'daily_ops',
                title: 'Daily operations summary',
                summary: 'Yesterday\'s board: what left, what arrived, what is waiting, and what the day burned.',
                category: 'ops',
                viewGate: null,
                exportAbility: 'reports.export.ops',
                param: 'day',
                builder: ReportDefinitions\DailyOperations::class,
                metricKeys: ['jobs.awaiting_decision', 'trips.on_road', 'trips.arrived', 'trips.pod_pending', 'trucks.workshop', 'fuel.issued_litres', 'fuel.issued_cost'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'margin_truck',
                title: 'Margin per truck',
                summary: 'What each truck\'s arrived trips earned against what they cost, over a window.',
                category: 'financial',
                viewGate: 'seeTripMargin',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\MarginByTruck::class,
                metricKeys: ['margin.earned', 'margin.cost', 'margin.value', 'margin.pct'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'margin_route',
                title: 'Margin per route',
                summary: 'The same arrived-trip population grouped by lane — which corridors pay and which only look busy.',
                category: 'financial',
                viewGate: 'seeTripMargin',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\MarginByRoute::class,
                metricKeys: ['margin.earned', 'margin.cost', 'margin.value', 'margin.pct'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'margin_customer',
                title: 'Margin per customer',
                summary: 'Revenue as the chargeable names it, cost through the trip\'s job — one unattributable line for ownerless trips.',
                category: 'financial',
                viewGate: 'seeTripMargin',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\MarginByCustomer::class,
                metricKeys: ['margin.earned', 'margin.cost', 'margin.value', 'margin.pct'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'ar_ageing',
                title: 'Receivables ageing and collections',
                summary: 'Who owes what, how long it has been owed, and what the window actually collected.',
                category: 'financial',
                viewGate: 'invoices.view',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\ReceivablesAgeing::class,
                metricKeys: ['ar.buckets', 'ar.oldest_due', 'ar.collected'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'fuel_usage',
                title: 'Fuel by truck and route',
                summary: 'Litres and cost issued per truck on each route, reversed slips excluded.',
                category: 'ops',
                viewGate: 'fuel.view',
                exportAbility: 'reports.export.ops',
                param: 'window',
                builder: ReportDefinitions\FuelUsage::class,
                metricKeys: ['fuel.issued_litres', 'fuel.issued_cost'],
                schedulable: true,
            ),
            new ReportDefinition(
                key: 'trial_balance',
                title: 'Trial balance',
                summary: 'The posted book in the ledger\'s own columns, through an as-at date.',
                category: 'financial',
                viewGate: 'ledger.view',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\LedgerTrialBalance::class,
                metricKeys: ['ledger.debits', 'ledger.credits'],
            ),
            new ReportDefinition(
                key: 'profit_loss',
                title: 'Profit and loss',
                summary: 'Net ledger movement on the profit-and-loss accounts across a window.',
                category: 'financial',
                viewGate: 'ledger.view',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\LedgerProfitLoss::class,
                metricKeys: ['pl.revenue', 'pl.expense', 'pl.net'],
            ),
            new ReportDefinition(
                key: 'balance_sheet',
                title: 'Balance sheet',
                summary: 'What the ledger held, owed and funded at the end of the window — open-period earnings carried on the equity side until a close rolls them in.',
                category: 'financial',
                viewGate: 'ledger.view',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\LedgerBalanceSheet::class,
                metricKeys: ['bs.assets', 'bs.liabilities', 'bs.equity', 'bs.earnings'],
            ),
            new ReportDefinition(
                key: 'cash_flow',
                title: 'Cash flow',
                summary: 'Money in and out of the linked bank and cash accounts, told through the counterparties of the entries that moved them.',
                category: 'financial',
                viewGate: 'ledger.view',
                exportAbility: 'reports.export.financial',
                param: 'window',
                builder: ReportDefinitions\LedgerCashFlow::class,
                metricKeys: ['cf.inflow', 'cf.outflow', 'cf.net', 'cf.closing'],
            ),
        ];
    }

    public function find(string $key): ?ReportDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The library as this seat sees it: `reports.view` opens the door (the route already asked),
     * and each report keeps its own extra gate. A report the seat cannot read is absent here —
     * the screen never renders a table whose columns it would have to hide anyway.
     *
     * @return list<ReportDefinition>
     */
    public function visibleToCurrentSeat(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ReportDefinition $d): bool => $d->visibleToCurrentSeat(),
        ));
    }
}
