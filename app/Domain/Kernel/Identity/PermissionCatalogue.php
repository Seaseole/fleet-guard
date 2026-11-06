<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

use InvalidArgumentException;

/**
 * The permission catalogue — the machine-readable form of the matrix.
 *
 * One source of names on purpose. The seeder creates exactly these rows, the role definitions
 * may only cite these names, the navigation config filters on them, the exports ask them, and
 * `fleet:permissions audit` compares the database against this list. A permission string that
 * exists only in a Blade file is a permission nobody can grant, which is the silent half of the
 * "field-level rules are auditable" promise in.
 *
 * Names are `capability.action`, lower snake, one dot. Deliberately not colons: The MFA rule
 * was written as "any role holding `financial:*` or `accounting:*` permissions", but a prefix
 * test on names would be a lie — the money-hiding permissions are spread over `trips.`, `fleet.`,
 * `bank.`, `ledger.` and `payroll.`. So the category is a property of each entry instead of a
 * naming accident, and `requiresMfa()` asks the category.
 */
final class PermissionCatalogue
{
    /**
     * Groups mirror the rows so the matrix and this file can be read side by side.
     *
     * @var array<string, array<string, string>>
     */
    private const GROUPS = [
        'Overview' => [
            'dashboard.view' => 'See the operational dashboard cards',
            'dashboard.financial' => 'See the financial dashboard cards',
            'alerts.view' => 'See the alert register',
            'alerts.acknowledge' => 'Acknowledge an alert or mute it with an end date',
            // Its own name rather than a second meaning on `alerts.acknowledge`, because `Resolved` is
            // the one state the engine cannot leave: `alerts_resolved_is_final` refuses every transition
            // out of it. A grant list that reads "acknowledge or mute" but also closes records
            // permanently is the silent half of the auditable-matrix promise.
            'alerts.resolve' => 'Close an alert with a reason — the register\'s terminal state',
        ],
        'Fleet' => [
            'fleet.view' => 'See vehicles',
            'fleet.create' => 'Add a vehicle',
            'fleet.update' => 'Edit a vehicle',
            'fleet.sell' => 'Record a disposal or sale',
            'fleet.cost.view' => 'See purchase price, finance and book value',
            'fleet.odometer.record' => 'Record odometer and engine hours',
        ],
        'Jobs and trips' => [
            'jobs.view' => 'See jobs',
            'jobs.create' => 'Create a job',
            'jobs.quote' => 'Quote a job',
            // `closed` is the one job state only the Owner may set, so it needs its own
            // permission instead of riding on `jobs.create`. The Owner is the only role that holds
            // every name, which is what makes that sentence enforceable rather than a comment.
            'jobs.close' => 'Close a job',
            'jobs.rate_cost.view' => 'See the rate versus cost of a job',
            'trips.view' => 'See trips',
            'trips.plan' => 'Plan a trip',
            'trips.dispatch' => 'Dispatch a trip',
            'trips.complete' => 'Complete a trip',
            // The `completed → closed` is an accounting act (it freezes the costs of a period),
            // which is why it is not folded into `trips.complete`.
            'trips.close' => 'Close a completed trip and freeze its costs',
            'trips.reopen' => 'Reopen a completed trip',
            'trips.margin.view' => 'See trip contribution and margin',
            'routes.view' => 'See routes and their stops',
            'routes.manage' => 'Edit routes, stops and rate cards',
            'pod.capture' => 'Capture a proof of delivery',
            'pod.approve' => 'Approve a proof of delivery',
        ],
        'Fuel' => [
            'fuel.view' => 'See fuel issues and purchases',
            'fuel.record' => 'Record fuel, including bulk issues',
            'fuel.correct' => 'Correct a posted fuel figure',
            'fuel.waive_variance' => 'Waive a consumption variance',
            'fuel.variance.view_cost' => 'See variance cost, not only litres',
        ],
        'Workshop' => [
            'maintenance.view' => 'See schedules, work orders and inspections',
            'work_orders.create' => 'Raise a work order',
            'work_orders.approve' => 'Approve work order spend',
            'work_orders.close' => 'Close a work order',
            'parts.view' => 'See parts stock',
            'parts.manage' => 'Add a part to the catalogue or edit its details',
            'parts.adjust' => 'Adjust parts stock',
            'parts.count' => 'Enter a stock count',
            'tyres.manage' => 'Register tyres, fit them and take them off',
            'inspections.record' => 'Record a pre-trip, periodic or tachograph inspection',
            'breakdowns.record' => 'Record a breakdown',
        ],
        'Invoicing' => [
            'invoices.view' => 'See invoices',
            'invoices.draft' => 'Draft an invoice',
            'invoices.issue' => 'Issue an invoice',
            'invoices.void' => 'Void an issued invoice',
            'payments.view' => 'See payments',
            'payments.allocate' => 'Allocate and reverse payment allocation',
            'credit_notes.manage' => 'Raise and issue credit notes',
            'customers.view' => 'See customers',
            'customers.manage' => 'Edit customer records',
            'customer.terms.view' => 'See customer credit terms and agreed rates',
        ],
        'Accounting' => [
            'accounting.view' => 'See expenses, bills and statements',
            'expenses.create' => 'Raise an expense',
            'suppliers.view' => 'See suppliers',
            'suppliers.manage' => 'Edit supplier records',
            'bills.create' => 'Enter a supplier bill',
            'bank.view' => 'See bank and cash accounts',
            'bank.manage' => 'Change bank and cash account details',
            'bank.number.view' => 'Reveal a full bank account number',
            'bank.reconcile' => 'Open, edit and sign bank and cash reconciliations',
            'ledger.view' => 'See the journal',
            'ledger.post' => 'Post a journal entry',
            'ledger.journal.manual' => 'Post a manual journal',
            'ledger.period.close' => 'Open or close an accounting period',
            'loans.view' => 'See loans and instalments',
            'loans.manage' => 'Manage loans and instalments',
        ],
        'Cutover' => [
            'cutover.import' => 'Upload, validate and preview a legacy cutover file',
            'cutover.post' => 'Post a validated cutover batch to the ledger',
        ],
        'People and payroll' => [
            'people.view' => 'See drivers and employees',
            'people.manage' => 'Edit driver and employee records',
            'payroll.view' => 'See salaries, advances and settlements',
            'payroll.manage' => 'Run salaries, advances and settlements',
            'settlements.view.own' => 'See the settlement for own trips',
        ],
        'Cross-border' => [
            'crossborder.view' => 'See crossings and border costs',
            'crossborder.manage' => 'Capture crossing costs, agents and permits',
            'crossborder.reconcile' => 'Reconcile an agent float',
        ],
        'Telematics' => [
            'gps.view' => 'See live positions and alerts',
        ],
        'Documents' => [
            'documents.view' => 'See documents',
            'documents.upload' => 'Upload a document',
            'documents.download' => 'Download a document',
            'documents.delete' => 'Delete a document version',
        ],
        'Reporting' => [
            'reports.view' => 'See the report library',
            'reports.export.ops' => 'Export operational reports',
            'reports.export.workshop' => 'Export workshop reports',
            'reports.export.stock' => 'Export stock reports',
            'reports.export.financial' => 'Export financial reports and the ledger',
        ],
        'Administration' => [
            'users.view' => 'See staff accounts and their roles',            'users.manage' => 'Invite staff, change a role, or deactivate an account',
            'roles.manage' => 'Change what a role can do',
            'settings.view' => 'See company settings',
            'settings.manage' => 'Change company settings',
            'numbering.manage' => 'Change document numbering formats and periods',
            'audit.view' => 'Read the audit trail',
            'sessions.manage' => 'Review and revoke active sessions',
            'queues.view' => 'Read deployment queue health',
            'queues.retry' => 'Retry a failed deployment job after re-authentication',
            'kiosk.token.issue' => 'Issue and revoke trip kiosk tokens',
            // The "silence is loud": the dashboard says when the books last left the building and
            // whether the copy read back. A backup nobody can see the age of is a promise, not a control.
            'backups.view' => 'See when the ledger was last exported off-site and whether the copy verified',
        ],
    ];

    /**
     * Permissions that move, reveal or export money. Holding any of these is what makes MFA
     * mandatory for the role, and what makes an export re-auth gated (legend ⚑).
     *
     * @var list<string>
     */
    private const FINANCIAL = [
        'dashboard.financial',
        'fleet.sell',
        'fleet.cost.view',
        'jobs.rate_cost.view',
        'trips.margin.view',
        'fuel.correct',
        'fuel.waive_variance',
        'fuel.variance.view_cost',
        'work_orders.approve',
        'invoices.view',
        'invoices.draft',
        'invoices.issue',
        'invoices.void',
        'payments.view',
        'payments.allocate',
        'credit_notes.manage',
        'customer.terms.view',
        'accounting.view',
        'expenses.create',
        'bills.create',
        'bank.view',
        'bank.manage',
        'bank.number.view',
        'ledger.view',
        'ledger.post',
        'ledger.journal.manual',
        'ledger.period.close',
        'loans.view',
        'loans.manage',
        // Importing a trial balance reveals the whole financial position, and posting one moves
        // every opening figure. Both are money, so both force MFA on any role that holds them.
        'cutover.import',
        'cutover.post',
        'payroll.view',
        'payroll.manage',
        'crossborder.manage',
        'crossborder.reconcile',
        'reports.export.financial',
    ];

    /**
     * The legend ⚑ column: abilities that must sit behind `reauth` — either they
     * move a posted number, reveal an secret, delete evidence, or change who can do any of that.
     *
     * @var list<string>
     */
    private const REAUTH = [
        'fleet.sell',
        'trips.reopen',
        'fuel.correct',
        'fuel.waive_variance',
        'work_orders.approve',
        'invoices.void',
        'payments.allocate',
        'bank.manage',
        'bank.number.view',
        'ledger.journal.manual',
        'ledger.period.close',
        'cutover.post',
        'payroll.manage',
        'documents.delete',
        'reports.export.financial',
        'crossborder.reconcile',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'numbering.manage',
        'queues.retry',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];

        foreach (self::GROUPS as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return array<string, array<string, string>> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function knows(string $name): bool
    {
        foreach (self::GROUPS as $permissions) {
            if (array_key_exists($name, $permissions)) {
                return true;
            }
        }

        return false;
    }

    public static function label(string $name): string
    {
        foreach (self::GROUPS as $permissions) {
            if (isset($permissions[$name])) {
                return $permissions[$name];
            }
        }

        throw new InvalidArgumentException("Permission '{$name}' is not in the catalogue.");
    }

    public static function isFinancial(string $name): bool
    {
        return in_array($name, self::FINANCIAL, true);
    }

    public static function requiresReauth(string $name): bool
    {
        return in_array($name, self::REAUTH, true);
    }

    /** @return list<string> */
    public static function financialNames(): array
    {
        return self::FINANCIAL;
    }

    /** @return list<string> */
    public static function reauthNames(): array
    {
        return self::REAUTH;
    }

    /**
     * Fail loudly on a typo in a role definition: a permission name that does not exist cannot be
     * granted, so a mis-keyed matrix row silently strips a role of a capability instead of
     * reporting it.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function checked(array $names, string $context): array
    {
        foreach ($names as $name) {
            if (! self::knows($name)) {
                throw new InvalidArgumentException("Unknown permission '{$name}' in {$context} — add it to the catalogue or fix the name.");
            }
        }

        return $names;
    }
}
