<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Identity;

/**
 * Where each ⚑ permission is enforced and where the password is asked again.
 *
 * `PermissionCatalogue::REAUTH` names the abilities that must sit behind `reauth`. A list of names
 * is a claim; this map is the evidence the claim is true, written down once so `security:audit` can
 * check it rather than a reviewer reading every Blade file. Three rules the audit enforces:
 *
 * 1. every file that authorises a ⚑ ability must be named here — a new `Gate::allows('invoices.void')`
 *    in an unlisted file fails the audit instead of shipping without a password prompt;
 * 2. every ⚑ ability with a door must name at least one re-auth site, and that site must carry a
 *    re-auth marker (route `reauth:`, `RequirePassword`, or {@see ConfirmsRecentPassword});
 * 3. an ability with no door at all is reported as a note, not a failure — a grant that no screen
 *    exercises is an unfinished feature, not an unguarded one. `ledger.period.close` and
 *    `payroll.manage`'s settlement half once sat in that state; both have doors now, and
 *    the salary-run half of payroll keeps the note until its screen lands.
 *
 * Paths are repository-relative with forward slashes, so the list reads the same on every OS.
 */
final class SensitiveActionMap
{
    /**
     * @return array<string, array{sites: list<string>, reauth: list<string>}>
     */
    public static function entries(): array
    {
        return [
            'fleet.sell' => [
                'sites' => [
                    'app/Domain/Fleet/Policies/TruckPolicy.php',
                    'app/Domain/Fleet/Policies/TrailerPolicy.php',
                    'app/Domain/Fleet/Policies/EquipmentPolicy.php',
                ],
                'reauth' => [
                    'resources/views/pages/fleet/⚡index.blade.php',
                    'resources/views/pages/fleet/⚡vehicle.blade.php',
                ],
            ],
            'trips.reopen' => [
                'sites' => [
                    'app/Domain/Operations/Policies/TripPolicy.php',
                ],
                'reauth' => [
                    'resources/views/pages/operations/⚡detail.blade.php',
                ],
            ],
            'fuel.correct' => [
                'sites' => [
                    'app/Domain/Fuel/Actions/ReverseFuelIssue.php',
                    'resources/views/pages/fuel/⚡trip-panel.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/fuel/⚡trip-panel.blade.php',
                ],
            ],
            'fuel.waive_variance' => [
                'sites' => [
                    'app/Domain/Fuel/Actions/DecideFuelVariance.php',
                    'resources/views/pages/fuel/⚡index.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/fuel/⚡index.blade.php',
                ],
            ],
            'work_orders.approve' => [
                'sites' => [
                    'app/Domain/Maintenance/Actions/ApproveWorkOrder.php',
                    'app/Domain/Maintenance/Actions/RejectWorkOrder.php',
                    'resources/views/pages/maintenance/⚡index.blade.php',
                    'resources/views/pages/maintenance/⚡work-order.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/maintenance/⚡work-order.blade.php',
                ],
            ],
            'invoices.void' => [
                'sites' => [
                    'app/Domain/Invoicing/Actions/VoidInvoice.php',
                    'resources/views/pages/invoicing/⚡invoice.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/invoicing/⚡invoice.blade.php',
                ],
            ],
            'payments.allocate' => [
                'sites' => [
                    'app/Domain/Invoicing/Actions/AllocatePayment.php',
                    'app/Domain/Invoicing/Actions/RecordPayment.php',
                    'resources/views/pages/invoicing/⚡invoice.blade.php',
                    // The deposit door: banked money with no invoice named is still somebody's
                    // cash moving, so it stands behind the same re-auth as a receipt on a document.
                    'resources/views/pages/invoicing/⚡customers.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/invoicing/⚡invoice.blade.php',
                    'resources/views/pages/invoicing/⚡customers.blade.php',
                ],
            ],
            'bank.manage' => [
                'sites' => [
                    'app/Domain/Accounting/Policies/BankAccountPolicy.php',
                    'resources/views/pages/accounting/⚡bank-accounts.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/accounting/⚡bank-accounts.blade.php',
                ],
            ],
            'bank.number.view' => [
                // The gate is asked through FieldGate::SeeBankDetail, whose value is this permission;
                // the enum is the one place the mapping lives, which is why it is the site.
                'sites' => [
                    'app/Domain/Kernel/Identity/FieldGate.php',
                ],
                'reauth' => [
                    'resources/views/pages/accounting/⚡bank-accounts.blade.php',
                ],
            ],
            'ledger.journal.manual' => [
                'sites' => [
                    'routes/accounting.php',
                    'resources/views/pages/accounting/⚡journal.blade.php',
                ],
                'reauth' => [
                    'routes/accounting.php',
                ],
            ],
            // The period-close door exists now: the screen re-authenticates every flip
            // (close, lock, reopen) through the trait, and the three Kernel Actions ask the
            // ability again at the row.
            'ledger.period.close' => [
                'sites' => [
                    'app/Domain/Kernel/Ledger/Actions/ClosePeriod.php',
                    'app/Domain/Kernel/Ledger/Actions/ReopenPeriod.php',
                    'app/Domain/Kernel/Ledger/Actions/LockPeriod.php',
                    'resources/views/pages/accounting/⚡periods.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/accounting/⚡periods.blade.php',
                ],
            ],
            'cutover.post' => [
                'sites' => [
                    'app/Domain/Kernel/Cutover/Policies/CutoverBatchPolicy.php',
                ],
                'reauth' => [
                    'resources/views/pages/accounting/⚡cutover.blade.php',
                ],
            ],
            // The settlement desk is payroll.manage's first door: the Actions ask the
            // ability, the settle step re-authenticates through the trait. Salary runs (the other
            // half of the payroll-lite) still have no screen, which the audit prints as a
            // note against the grant, not a failure. The design adds the second door: an opening advance
            // is the same money onto the same account, and the screen that applies it is the cutover
            // page, which re-authenticates for its own ability above.
            'payroll.manage' => [
                'sites' => [
                    'app/Domain/People/Policies/TripSettlementPolicy.php',
                    'app/Domain/People/Actions/RecordOpeningAdvance.php',
                    'resources/views/pages/people/⚡settlements.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/people/⚡settlements.blade.php',
                ],
            ],
            'documents.delete' => [
                'sites' => [
                    'app/Domain/Kernel/Documents/Policies/DocumentPolicy.php',
                ],
                'reauth' => [
                    'resources/views/pages/documents/⚡documents.blade.php',
                ],
            ],
            'reports.export.financial' => [
                // The catalogue carries the ability per report and the two queue actions ask it;
                // the download controller re-asks the ones whose report is financial.
                'sites' => [
                    'app/Domain/Reporting/ReportsCatalogue.php',
                    'app/Domain/Reporting/Actions/QueueReportExport.php',
                    'app/Domain/Reporting/Actions/UpsertScheduledReport.php',
                ],
                'reauth' => [
                    'app/Http/Controllers/Reporting/DownloadReportExport.php',
                ],
            ],
            // Agent-float reconciliation is Phase 6 work; the grant precedes its screen.
            'crossborder.reconcile' => [
                'sites' => [],
                'reauth' => [],
            ],
            'users.manage' => [
                'sites' => [
                    'app/Domain/Security/Policies/UserPolicy.php',
                    'app/Domain/Security/Policies/InvitationPolicy.php',
                    'resources/views/pages/security/⚡users.blade.php',
                ],
                'reauth' => [
                    'resources/views/pages/security/⚡users.blade.php',
                ],
            ],
            // Roles are code-defined in this build (RoleKey); there is no editor to guard.
            'roles.manage' => [
                'sites' => [],
                'reauth' => [],
            ],
            'settings.manage' => [
                'sites' => [
                    'app/Domain/Invoicing/Actions/SetStandardVatRate.php',
                    'resources/views/pages/settings/⚡application.blade.php',
                ],
                // The route carries `reauth`; RequirePassword is persistent middleware, so a
                // Livewire update from this page re-runs it.
                'reauth' => [
                    'routes/settings.php',
                ],
            ],
            // Numbering formats are edited in a later settings screen; the grant is unused today.
            'numbering.manage' => [
                'sites' => [],
                'reauth' => [],
            ],
            'queues.retry' => [
                'sites' => [
                    'app/Domain/Security/Actions/RetryFailedJob.php',
                    'resources/views/pages/security/⚡queues.blade.php',
                ],
                'reauth' => [
                    'app/Domain/Security/Actions/RetryFailedJob.php',
                    'routes/security.php',
                ],
            ],
        ];
    }
}
