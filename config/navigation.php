<?php

/*
 | One place defines the application menu; the sidebar and the command palette
 | both read it and filter by permission (DESIGN.md §3.2, DESIGN-SYSTEM.md §7).
 |
 | 'permission' keys are checked with Gate::check. Entries without one render for
 | every authenticated user, so this file can be extended before the roles exist.
 | 'badge' resolves to a count of things needing action, never a raw unread count.
 */

return [
    [
        'label' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
        ],
    ],
    [
        'label' => 'Operations',
        'items' => [
            ['label' => 'Fleet', 'route' => 'fleet.index', 'icon' => 'truck', 'permission' => 'fleet.view', 'phase' => 1],
            ['label' => 'Dispatch', 'route' => 'operations.dispatch', 'icon' => 'map', 'permission' => 'trips.view', 'phase' => 2],
            ['label' => 'Jobs', 'route' => 'operations.jobs', 'icon' => 'clipboard-document-list', 'permission' => 'jobs.view', 'phase' => 2],
            ['label' => 'Routes', 'route' => 'operations.routes', 'icon' => 'map', 'permission' => 'routes.view', 'phase' => 1],
            ['label' => 'Cross-border', 'route' => 'crossborder.index', 'icon' => 'globe-alt', 'permission' => 'crossborder.view', 'phase' => 2],
            ['label' => 'Live map', 'route' => 'telematics.live', 'icon' => 'signal', 'permission' => 'gps.view', 'phase' => 2],
        ],
    ],
    [
        'label' => 'Workshop',
        'items' => [
            ['label' => 'Fuel', 'route' => 'fuel.index', 'icon' => 'bolt', 'permission' => 'fuel.view', 'phase' => 3],
            ['label' => 'Maintenance', 'route' => 'maintenance.index', 'icon' => 'wrench', 'permission' => 'maintenance.view', 'phase' => 3],
            ['label' => 'Parts', 'route' => 'maintenance.parts', 'icon' => 'cube', 'permission' => 'parts.view', 'phase' => 1],
        ],
    ],
    [
        'label' => 'Money',
        'items' => [
            ['label' => 'Invoices', 'route' => 'invoicing.index', 'icon' => 'receipt-percent', 'permission' => 'invoices.view', 'phase' => 4],
            ['label' => 'Customers', 'route' => 'invoicing.customers', 'icon' => 'building-storefront', 'permission' => 'customers.view', 'phase' => 1],
            ['label' => 'Suppliers', 'route' => 'accounting.suppliers', 'icon' => 'scale', 'permission' => 'suppliers.view', 'phase' => 1],
            ['label' => 'Accounting', 'route' => 'accounting.journal', 'icon' => 'calculator', 'permission' => 'ledger.view', 'phase' => 0],
            ['label' => 'Bank & cash', 'route' => 'accounting.bank-accounts', 'icon' => 'banknotes', 'permission' => 'bank.view', 'phase' => 0],
            ['label' => 'Cutover', 'route' => 'accounting.cutover', 'icon' => 'circle-stack', 'permission' => 'cutover.import', 'phase' => 0.5],
            ['label' => 'Loans & cash flow', 'route' => 'accounting.loans', 'icon' => 'banknotes', 'permission' => 'loans.view', 'phase' => 5],
            ['label' => 'People', 'route' => 'people.index', 'icon' => 'users', 'permission' => 'people.view', 'phase' => 1],
        ],
    ],
    [
        'label' => 'Library',
        'items' => [
            ['label' => 'Documents', 'route' => 'documents.index', 'icon' => 'folder', 'permission' => 'documents.view', 'phase' => 0],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'permission' => 'reports.view', 'phase' => 8],
            ['label' => 'Alerts', 'route' => 'alerts.index', 'icon' => 'bell-alert', 'permission' => 'alerts.view', 'phase' => 0],
        ],
    ],
    [
        'label' => 'Administration',
        'items' => [
            ['label' => 'Settings', 'route' => 'profile.edit', 'icon' => 'cog'],
            ['label' => 'Users & roles', 'route' => 'security.users', 'icon' => 'shield-check', 'permission' => 'users.view', 'phase' => 0],
            ['label' => 'Queue health', 'route' => 'security.queues', 'icon' => 'signal', 'permission' => 'queues.view', 'phase' => 0],
        ],
    ],
];
