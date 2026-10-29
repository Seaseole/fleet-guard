<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

/**
 * The seven staff roles of §9.2, with the permission set each one holds.
 *
 * The matrix in §9.3 is the source; this enum is that matrix made executable, so "what can a
 * Dispatcher do" has one answer in the codebase rather than one in a document and one in a
 * seeder. Two principals in §9.2 are deliberately **not** here: the Kiosk token and System are
 * not roles — a token carries scopes on `trip_access_tokens` (ADR-0006) and the scheduler has no
 * interactive session, so neither can be invited, logged in as, or granted a role.
 *
 * Roles are company-keyed rows (§8), so the seeder creates one set per company rather than
 * relying on spatie's global-role escape hatch (`roles.company_id IS NULL`), which this project
 * treats as a bug: a role that exists for every company is a role nobody owns.
 */
enum RoleKey: string
{
    case Owner = 'owner';
    case OperationsManager = 'operations_manager';
    case Dispatcher = 'dispatcher';
    case WorkshopManager = 'workshop_manager';
    case StoresClerk = 'stores_clerk';
    case Accountant = 'accountant';
    case Auditor = 'auditor';

    /** @return list<string> */
    public static function invitable(): array
    {
        // The Owner seat is created once by `fleet:setup-owner` and is never granted to an
        // invitation: §9.3 gives it sole rights over users, roles and permissions, so making it
        // selectable in an invite form is the privilege-escalation path the design closes.
        return [
            self::OperationsManager->value,
            self::Dispatcher->value,
            self::WorkshopManager->value,
            self::StoresClerk->value,
            self::Accountant->value,
            self::Auditor->value,
        ];
    }

    public static function fromKey(string|self $key): self
    {
        $value = $key instanceof self ? $key->value : $key;

        $case = self::tryFrom($value);

        if ($case === null) {
            throw new \InvalidArgumentException("Unknown role key '{$value}' (§9.2).");
        }

        return $case;
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::OperationsManager => 'Operations Manager',
            self::Dispatcher => 'Dispatcher',
            self::WorkshopManager => 'Workshop Manager',
            self::StoresClerk => 'Stores Clerk',
            self::Accountant => 'Accountant',
            self::Auditor => 'Read-only Auditor',
        };
    }

    /** The one-line job description shown on the users screen and in the invite email. */
    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including staff, roles and period locks.',
            self::OperationsManager => 'Runs the fleet and the money around it; cannot change staff or settings.',
            self::Dispatcher => 'Plans and dispatches trips, captures PODs. Sees litres, never margins.',
            self::WorkshopManager => 'Work orders, parts counts and odometer on own trucks.',
            self::StoresClerk => 'Parts stock and bulk fuel issues.',
            self::Accountant => 'Invoicing, banking, the ledger, payroll and period close.',
            self::Auditor => 'Reads everything, changes nothing, exports only after re-authentication.',
        };
    }

    /**
     * @return list<string>
     *
     * `PermissionCatalogue::checked()` runs over every list, so a renamed or deleted permission
     * fails the seeder instead of quietly leaving a role short.
     */
    public function permissions(): array
    {
        $permissions = match ($this) {
            self::Owner => PermissionCatalogue::names(),

            self::OperationsManager => [
                'dashboard.view', 'dashboard.financial',
                'fleet.view', 'fleet.create', 'fleet.update', 'fleet.sell', 'fleet.odometer.record',
                'jobs.view', 'jobs.create', 'jobs.quote', 'jobs.rate_cost.view',
                'trips.view', 'trips.plan', 'trips.dispatch', 'trips.complete', 'trips.reopen',
                'trips.margin.view',
                'routes.view', 'routes.manage',
                'pod.capture', 'pod.approve',
                'fuel.view', 'fuel.record', 'fuel.correct', 'fuel.waive_variance', 'fuel.variance.view_cost',
                'maintenance.view', 'work_orders.create', 'work_orders.approve', 'work_orders.close',
                'parts.view', 'parts.adjust',
                'invoices.view', 'payments.view',
                'customers.view', 'customer.terms.view',
                'accounting.view', 'expenses.create', 'bills.create', 'suppliers.view', 'bank.view',
                'ledger.view', 'loans.view', 'payroll.view',
                'people.view', 'people.manage',
                'crossborder.view', 'crossborder.manage', 'crossborder.reconcile',
                'gps.view',
                'documents.view', 'documents.upload', 'documents.download',
                'reports.view', 'reports.export.ops', 'reports.export.workshop', 'reports.export.stock',
                'reports.export.financial',
                'settings.view',
                'audit.view', 'alerts.view', 'alerts.acknowledge', 'alerts.resolve',
                'kiosk.token.issue',
            ],

            // "a dispatcher must not see margins": no trips.margin.view, no fleet.cost.view, no
            // jobs.rate_cost.view, no fuel.variance.view_cost, no reports.export.financial (§9.3).
            self::Dispatcher => [
                'dashboard.view',
                'fleet.view',
                'jobs.view', 'jobs.create', 'jobs.quote',
                'trips.view', 'trips.plan', 'trips.dispatch',
                'routes.view',
                'pod.capture', 'pod.approve',
                'fuel.view',
                'maintenance.view',
                'invoices.view',
                'customers.view',
                'people.view',
                'crossborder.view',
                'gps.view',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export.ops',
                // §14.1's own example is a `doc_expiry` alert delivered to the Operations Manager *and*
                // the Dispatcher, with both of them acting on it — so the roles the engine delivers to
                // hold `alerts.acknowledge`. `alerts.resolve` is not in this list: Resolve is terminal
                // (`alerts_resolved_is_final`), and closing a compliance finding is the Owner's and the
                // Operations Manager's call, not the person who booked the truck.
                'alerts.view', 'alerts.acknowledge',
                'kiosk.token.issue',
            ],

            self::WorkshopManager => [
                'dashboard.view',
                'fleet.view', 'fleet.odometer.record',
                'trips.view',
                'fuel.view', 'fuel.variance.view_cost',
                'maintenance.view', 'work_orders.create', 'work_orders.close',
                'parts.view', 'parts.manage', 'parts.count',
                'accounting.view', 'expenses.create', 'suppliers.view',
                'gps.view',
                'documents.view', 'documents.upload', 'documents.download',
                'reports.view', 'reports.export.workshop',
                // The roadworthiness and workshop papers are delivered here by the engine's own default
                // recipients, so this role may acknowledge and mute what it receives (§14.1).
                'alerts.view', 'alerts.acknowledge',
            ],

            self::StoresClerk => [
                'dashboard.view',
                'fleet.view',
                'trips.view',
                'fuel.view', 'fuel.record',
                'maintenance.view', 'work_orders.create',
                'parts.view', 'parts.manage', 'parts.adjust', 'parts.count',
                'accounting.view', 'expenses.create', 'suppliers.view',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export.stock',
                'alerts.view',
            ],

            self::Accountant => [
                'dashboard.view', 'dashboard.financial',
                'fleet.view', 'fleet.cost.view',
                'jobs.view',
                // §10.2 puts `completed → closed` with the Accountant and the Owner: closing is what
                // freezes a period's costs, so it is an accounting act, not a dispatch one.
                'trips.view', 'trips.close', 'trips.margin.view',
                'routes.view',
                'fuel.view', 'fuel.variance.view_cost',
                'maintenance.view',
                'parts.view',
                'invoices.view', 'invoices.draft', 'invoices.issue', 'invoices.void',
                'payments.view', 'payments.allocate', 'credit_notes.manage',
                'customers.view', 'customers.manage', 'customer.terms.view',
                'accounting.view', 'expenses.create', 'bills.create',
                'suppliers.view', 'suppliers.manage',
                'cutover.import',
                'bank.view', 'bank.manage', 'bank.number.view',
                'ledger.view', 'ledger.post', 'ledger.journal.manual', 'ledger.period.close',
                'loans.view', 'loans.manage',
                'people.view', 'payroll.view', 'payroll.manage', 'settlements.view.own',
                'crossborder.view', 'crossborder.manage', 'crossborder.reconcile',
                'gps.view',
                'documents.view', 'documents.upload', 'documents.download',
                'reports.view', 'reports.export.financial',
                'settings.view', 'numbering.manage',
                'audit.view', 'alerts.view',
            ],

            // Read-only across the board (§9.2): every row is R, nothing is C/A, and the bank rows
            // stay masked ("R (masked)"). `bank.number.view` is therefore absent even though the
            // Auditor sees everything else, and `settings.view` is absent because §9.3 gives the
            // Settings row "–" to this role. The one thing an Auditor holds that no other
            // read-mostly role has is audit.view, which is what the role is for.
            self::Auditor => [
                'dashboard.view', 'dashboard.financial',
                'fleet.view', 'fleet.cost.view',
                'jobs.view', 'jobs.rate_cost.view',
                'trips.view', 'trips.margin.view',
                'routes.view',
                'fuel.view', 'fuel.variance.view_cost',
                'maintenance.view', 'parts.view',
                'invoices.view', 'payments.view', 'customers.view', 'customer.terms.view',
                'accounting.view', 'bank.view', 'suppliers.view',
                'ledger.view', 'loans.view',
                'people.view', 'payroll.view', 'settlements.view.own',
                'crossborder.view',
                'gps.view',
                'documents.view', 'documents.download',
                'reports.view', 'reports.export.financial',
                'users.view',
                'audit.view', 'alerts.view',
            ],
        };

        return PermissionCatalogue::checked($permissions, "role '{$this->label()}'");
    }

    /**
     * §9.1: MFA is mandatory for the Owner and for any role that can see or move money. Derived
     * from the catalogue category rather than hard-coded per role, so adding a financial
     * permission to a role changes its MFA requirement without anyone remembering to edit a
     * middleware.
     */
    public function requiresMfa(): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        foreach ($this->permissions() as $permission) {
            if (PermissionCatalogue::isFinancial($permission)) {
                return true;
            }
        }

        return false;
    }

    /** The masked/unmasked rule for bank numbers (§9.3 "R (masked)" rows). */
    public function canSeeBankNumber(): bool
    {
        return in_array('bank.number.view', $this->permissions(), true);
    }
}
