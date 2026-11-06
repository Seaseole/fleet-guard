<?php

declare(strict_types=1);

/*
 * The "query-count assertion in the local suite's page tests" and the "page budgets", made
 * into data rather than a paragraph. `php artisan performance:audit` fails when a page route has no
 * entry here, and `scripts/perf-budget.php` fails when a page exceeds its numbers.
 *
 * A budget is a **ceiling on work, not a stopwatch on the server**: the query count is the part that
 * is the same on a laptop and on Cloud, so it is the number a regression trips first. The millisecond
 * figure is measured against the local MySQL and the dev database's row counts, so it carries slack —
 * it catches an accidental full-table scan or a page that loads every document, not a 20 ms wobble.
 *
 * `register` marks a screen that lists a growing table: the audit then insists its component paginates
 * rather than loading every row and slicing it in memory.
 */
return [
    /*
     * Each ceiling was measured on 2026-11-01 with `scripts/perf-budget.php` against the dev database,
     * then given roughly a quarter of headroom (minimum five queries). The two screens with no fixture
     * row on this database — a bill detail and a work order — are set from their nearest sibling.
     *
     * Raising a number is a decision, not a fix: it belongs in a commit message beside the reason the
     * page needs the extra work.
     */
    'pages' => [
        // Raised from 80 on 2026-11-01 for one reason: the dashboard now draws four charts, and the trend among
        // them is six reads of the ledger's own windowed door rather than one grouped query — rule D3 and
        // DESIGN.md the design forbid a dashboard grouping the journal for itself, so the six are the price of the
        // picture being the same figure the signed sheet is. Measured at 82 with `scripts/perf-budget.php` after
        // the duplicate monthly sheet was collapsed into one read; the headroom is the file's usual quarter.
        'dashboard' => ['queries' => 100, 'ms' => 800, 'register' => false],
        'alerts.index' => ['queries' => 17, 'ms' => 500, 'register' => true],
        'accounting.bank-accounts' => ['queries' => 16, 'ms' => 500, 'register' => true], // 2026-11-02: +1 for the chart read behind the new Ledger account picker
        // The two sheets read the chart plus the ledger doors only: the balance sheet is
        // one statementTotals pass, the cash-flow sheet adds the counterparty pass and the cash
        // register read — hence one budget above the trial balance's, not a new class of page.
        'accounting.balance-sheet' => ['queries' => 16, 'ms' => 600, 'register' => false],
        'accounting.cash-flow' => ['queries' => 18, 'ms' => 600, 'register' => false],
        'accounting.bills' => ['queries' => 17, 'ms' => 600, 'register' => true],
        'accounting.bills.show' => ['queries' => 35, 'ms' => 700, 'register' => false],
        'accounting.cutover' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'accounting.journal' => ['queries' => 17, 'ms' => 500, 'register' => true],
        'accounting.journal.create' => ['queries' => 13, 'ms' => 500, 'register' => false],
        // The pre-close report runs the verifier sweep, the tie-out command and the
        // movement/reconciliation reads on every render — the price of "the button answers to a
        // report", measured before raising. register=false: it is a workbench, not a list.
        'accounting.periods' => ['queries' => 26, 'ms' => 900, 'register' => false],
        // The book page carries the create form's three pickers; the loan page reads the
        // plan rows it shows; the forecast is three scheduled populations and one cash read.
        'accounting.loans' => ['queries' => 17, 'ms' => 600, 'register' => true],
        'accounting.loans.show' => ['queries' => 16, 'ms' => 600, 'register' => false],
        'accounting.cash-forecast' => ['queries' => 18, 'ms' => 700, 'register' => false],
        'accounting.profit-loss' => ['queries' => 14, 'ms' => 500, 'register' => false],
        // The register of runs is a paginated list of its own table (one ledger-free read);
        // the run page reads the live book through statementTotals once per render, so it carries
        // the statement-page ceiling rather than the bills.show one.
        'accounting.reconciliations' => ['queries' => 15, 'ms' => 500, 'register' => true],
        'accounting.reconciliations.show' => ['queries' => 16, 'ms' => 600, 'register' => false],
        'accounting.suppliers' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'accounting.trial-balance' => ['queries' => 14, 'ms' => 500, 'register' => false],
        'crossborder.index' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'documents.index' => ['queries' => 15, 'ms' => 600, 'register' => true],
        'documents.register' => ['queries' => 12, 'ms' => 500, 'register' => false],
        'fleet.index' => ['queries' => 18, 'ms' => 700, 'register' => true],
        'fleet.show' => ['queries' => 30, 'ms' => 600, 'register' => false],
        'fuel.index' => ['queries' => 40, 'ms' => 700, 'register' => true],
        'invoicing.ageing' => ['queries' => 14, 'ms' => 500, 'register' => false],
        'invoicing.customers' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'invoicing.index' => ['queries' => 17, 'ms' => 500, 'register' => true],
        'invoicing.invoices.show' => ['queries' => 20, 'ms' => 500, 'register' => false],
        'maintenance.breakdowns' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'maintenance.index' => ['queries' => 20, 'ms' => 500, 'register' => true],
        'maintenance.inspections' => ['queries' => 16, 'ms' => 500, 'register' => true],
        'maintenance.parts' => ['queries' => 17, 'ms' => 500, 'register' => true],
        'maintenance.tyres' => ['queries' => 15, 'ms' => 500, 'register' => true],
        'maintenance.work_order' => ['queries' => 45, 'ms' => 800, 'register' => false],
        'operations.dispatch' => ['queries' => 30, 'ms' => 700, 'register' => false],
        'operations.jobs' => ['queries' => 25, 'ms' => 800, 'register' => true],
        'operations.routes' => ['queries' => 14, 'ms' => 500, 'register' => true],
        'operations.trip' => ['queries' => 38, 'ms' => 600, 'register' => false],
        'people.index' => ['queries' => 14, 'ms' => 600, 'register' => true],
        // The desk reads its own table, a capped trip picker and two cost sums per prefill.
        'people.settlements' => ['queries' => 20, 'ms' => 700, 'register' => true],
        'reports.index' => ['queries' => 15, 'ms' => 500, 'register' => false],
        'security.queues' => ['queries' => 35, 'ms' => 500, 'register' => true],
        'security.users' => ['queries' => 16, 'ms' => 500, 'register' => true],
        'application.edit' => ['queries' => 13, 'ms' => 500, 'register' => false],
        'appearance.edit' => ['queries' => 12, 'ms' => 500, 'register' => false],
        'profile.edit' => ['queries' => 12, 'ms' => 500, 'register' => false],
        'security.edit' => ['queries' => 13, 'ms' => 500, 'register' => false],
        'sessions.edit' => ['queries' => 13, 'ms' => 1200, 'register' => false],
        'telematics.live' => ['queries' => 15, 'ms' => 500, 'register' => false],
    ],

    /*
     * Pages that are deliberately outside the budget list, each with the reason. A new page lands in
     * neither list until somebody writes it down — which is the finding.
     *
     * @var array<string, string>
     */
    'excluded' => [
        'kiosk.capture' => 'driver token surface: no session, no owner to authenticate the sweep as',
        'kiosk.fuel' => 'driver token surface, same reason',
        'security.invitations.accept' => 'the invitation token in the path is the only visitor',
        'password.*' => 'unauthenticated Fortify screens',
        'verification.*' => 'unauthenticated Fortify screens',
        'two-factor.*' => 'unauthenticated Fortify screens',
        'passkey.*' => 'unauthenticated Fortify screens',
    ],
];
