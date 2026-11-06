<?php

/*
 | One place defines the application menu; the sidebar and the command palette both read it and filter by
 | permission.
 |
 | 'permission' keys are checked with Gate::check. Entries without one render for every authenticated user,
 | so this file can be extended before the roles exist.
 | 'badge' resolves to a count of things needing action, never a raw unread count.
 |
 | GROUPED BY INTENT, NOT BY MODULE. The previous sections were the codebase's folder names (Operations,
 | Workshop, Money, Library), which put 17 of 33 entries in one wall and made a seat hunt for "close the
 | month" between Loans and Cutover. A section now answers what the person in front of the screen came to
 | do: what is moving, what I own, where the work is, who owes us, what the books say, is the month closed,
 | and the desk. Routes, permissions and phase keys are unchanged — this is the same menu read in a
 | different order, so every Gate result and every `Route::has` guard behaves exactly as before.
 */

return [
    [
        'label' => 'Now',
        'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Live map', 'route' => 'telematics.live', 'icon' => 'signal', 'permission' => 'gps.view', 'phase' => 2],
            ['label' => 'Alerts', 'route' => 'alerts.index', 'icon' => 'bell-alert', 'permission' => 'alerts.view', 'phase' => 0],
        ],
    ],
    [
        'label' => 'Fleet',
        'items' => [
            ['label' => 'Vehicles', 'route' => 'fleet.index', 'icon' => 'truck', 'permission' => 'fleet.view', 'phase' => 1],
            ['label' => 'Fuel', 'route' => 'fuel.index', 'icon' => 'circle-stack', 'permission' => 'fuel.view', 'phase' => 3],
            ['label' => 'Work orders', 'route' => 'maintenance.index', 'icon' => 'wrench', 'permission' => 'maintenance.view', 'phase' => 3],
            ['label' => 'Tyres', 'route' => 'maintenance.tyres', 'icon' => 'arrow-path', 'permission' => 'maintenance.view', 'phase' => 3],
            ['label' => 'Inspections', 'route' => 'maintenance.inspections', 'icon' => 'check-circle', 'permission' => 'maintenance.view', 'phase' => 3],
            ['label' => 'Breakdowns', 'route' => 'maintenance.breakdowns', 'icon' => 'exclamation-triangle', 'permission' => 'maintenance.view', 'phase' => 3],
            ['label' => 'Parts', 'route' => 'maintenance.parts', 'icon' => 'cube', 'permission' => 'parts.view', 'phase' => 1],
        ],
    ],
    [
        'label' => 'Trips',
        'items' => [
            ['label' => 'Dispatch', 'route' => 'operations.dispatch', 'icon' => 'map', 'permission' => 'trips.view', 'phase' => 2],
            ['label' => 'Jobs', 'route' => 'operations.jobs', 'icon' => 'clipboard-document-list', 'permission' => 'jobs.view', 'phase' => 2],
            ['label' => 'Routes', 'route' => 'operations.routes', 'icon' => 'map', 'permission' => 'routes.view', 'phase' => 1],
            ['label' => 'Cross-border', 'route' => 'crossborder.index', 'icon' => 'globe-alt', 'permission' => 'crossborder.view', 'phase' => 2],
            ['label' => 'Documents', 'route' => 'documents.index', 'icon' => 'folder', 'permission' => 'documents.view', 'phase' => 0],
        ],
    ],
    [
        'label' => 'Money in',
        'items' => [
            ['label' => 'Invoices', 'route' => 'invoicing.index', 'icon' => 'receipt-percent', 'permission' => 'invoices.view', 'phase' => 4],
            ['label' => 'Customers', 'route' => 'invoicing.customers', 'icon' => 'building-storefront', 'permission' => 'customers.view', 'phase' => 1],
        ],
    ],
    [
        'label' => 'Money out',
        'items' => [
            ['label' => 'Bills', 'route' => 'accounting.bills', 'icon' => 'document-text', 'permission' => 'accounting.view', 'phase' => 5],
            ['label' => 'Suppliers', 'route' => 'accounting.suppliers', 'icon' => 'scale', 'permission' => 'suppliers.view', 'phase' => 1],
        ],
    ],
    [
        'label' => 'Books',
        'items' => [
            ['label' => 'Journal', 'route' => 'accounting.journal', 'icon' => 'calculator', 'permission' => 'ledger.view', 'phase' => 0],
            ['label' => 'Trial balance', 'route' => 'accounting.trial-balance', 'icon' => 'scale', 'permission' => 'ledger.view', 'phase' => 5],
            ['label' => 'Profit & loss', 'route' => 'accounting.profit-loss', 'icon' => 'chart-bar', 'permission' => 'ledger.view', 'phase' => 5],
            ['label' => 'Balance sheet', 'route' => 'accounting.balance-sheet', 'icon' => 'scale', 'permission' => 'ledger.view', 'phase' => 5],
            ['label' => 'Cash flow', 'route' => 'accounting.cash-flow', 'icon' => 'banknotes', 'permission' => 'ledger.view', 'phase' => 5],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'permission' => 'reports.view', 'phase' => 8],
        ],
    ],
    [
        'label' => 'Bank & close',
        'items' => [
            ['label' => 'Bank & cash', 'route' => 'accounting.bank-accounts', 'icon' => 'banknotes', 'permission' => 'bank.view', 'phase' => 0],
            ['label' => 'Reconciliations', 'route' => 'accounting.reconciliations', 'icon' => 'clipboard-document-list', 'permission' => 'bank.view', 'phase' => 5],
            ['label' => 'Periods', 'route' => 'accounting.periods', 'icon' => 'clock', 'permission' => 'ledger.view', 'phase' => 5],
            ['label' => 'Loans', 'route' => 'accounting.loans', 'icon' => 'banknotes', 'permission' => 'loans.view', 'phase' => 5],
            ['label' => 'Cash forecast', 'route' => 'accounting.cash-forecast', 'icon' => 'chart-bar', 'permission' => 'loans.view', 'phase' => 5],
            ['label' => 'Settlements', 'route' => 'people.settlements', 'icon' => 'clipboard-document-list', 'permission' => 'payroll.view', 'phase' => 5],
            ['label' => 'Cutover', 'route' => 'accounting.cutover', 'icon' => 'circle-stack', 'permission' => 'cutover.import', 'phase' => 0.5],
        ],
    ],
    [
        'label' => 'Desk',
        'items' => [
            ['label' => 'People', 'route' => 'people.index', 'icon' => 'users', 'permission' => 'people.view', 'phase' => 1],
            ['label' => 'Settings', 'route' => 'profile.edit', 'icon' => 'cog'],
            ['label' => 'Users & roles', 'route' => 'security.users', 'icon' => 'shield-check', 'permission' => 'users.view', 'phase' => 0],
            ['label' => 'Queue health', 'route' => 'security.queues', 'icon' => 'signal', 'permission' => 'queues.view', 'phase' => 0],
        ],
    ],
];
