<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Accounting screens. Phase 0 ships three of them: the bank and cash register, the
 * read-only journal browser, and the manual journal — the last two land here because the
 * definition of done needs "a manually posted balanced journal (and one rejected unbalanced
 * attempt)" and a person's way to see it afterwards. Period close, the trial balance and COA
 * maintenance stay in Phase 5; the ledger they read is already real.
 *
 * Draws the line between the two ledger screens. Reading the journal is an ordinary
 * permission (`ledger.view`); writing into it is a ⚑ re-auth ability
 * (PermissionCatalogue::REAUTH :: ledger.journal.manual), so the create route carries `reauth`
 * as well as `can:`. A posted entry cannot be edited or deleted, which is precisely why
 * the door in front of it is the strictest one in the application.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('accounting/bank-accounts', 'pages::accounting.bank-accounts')
        ->middleware('can:bank.view')
        ->name('accounting.bank-accounts');

    /*
     * Suppliers are Accounting master data (`SUPPLIER ||--o{ BILL`), shipped in Phase 1 because
     * expenses already name one. The workshop and the stores hold `suppliers.view` without holding
     * `accounting.view`, which is why this is its own route and not a tab on the journal screen.
     */
    Route::livewire('suppliers', 'pages::accounting.suppliers')
        ->middleware('can:suppliers.view')
        ->name('accounting.suppliers');

    Route::livewire('accounting/journal', 'pages::accounting.journal')
        ->middleware('can:ledger.view')
        ->name('accounting.journal');

    /*
     * The design puts the trial balance and P&L in Phase 5's accounting; they land as Phase 8's first
     * slice because the design makes them the tie-out's anchor — the report library's every number must
     * agree with a statement, and until a statement screen existed the agreement had no witness.
     * Both read through the ledger doors under the same `ledger.view` the journal browser holds;
     * they add no figures of their own, so they need no stronger gate.
     */
    Route::livewire('accounting/trial-balance', 'pages::accounting.trial-balance')
        ->middleware('can:ledger.view')
        ->name('accounting.trial-balance');

    Route::livewire('accounting/profit-loss', 'pages::accounting.profit-loss')
        ->middleware('can:ledger.view')
        ->name('accounting.profit-loss');

    /*
     * The other two sheets of Phase 5's statement set: the balance sheet (cumulative
     * movement on the balance-sheet axis, open-period earnings carried on the equity side) and
     * the direct-method cash-flow statement (counterparties of entries that touched a linked
     * bank or cash account). Both read the same ledger doors as the trial balance and add no
     * figures of their own, so `ledger.view` is still the whole gate.
     */
    Route::livewire('accounting/balance-sheet', 'pages::accounting.balance-sheet')
        ->middleware('can:ledger.view')
        ->name('accounting.balance-sheet');

    Route::livewire('accounting/cash-flow', 'pages::accounting.cash-flow')
        ->middleware('can:ledger.view')
        ->name('accounting.cash-flow');

    /*
     * (Phase 5 slice 5d) — bank and cash reconciliations. Reading runs is `bank.view`
     * (the "see bank and cash accounts"); every mutation — open, add/remove lines, sign,
     * void — asks `bank.reconcile` again inside the Actions, because a screen is not a
     * permission check. The detail route binds the ulid run directly.
     */
    Route::livewire('accounting/reconciliations', 'pages::accounting.reconciliations')
        ->middleware('can:bank.view')
        ->name('accounting.reconciliations');

    Route::livewire('accounting/reconciliations/{reconciliation}', 'pages::accounting.reconciliation')
        ->middleware('can:bank.view')
        ->name('accounting.reconciliations.show');

    /*
     * (Phase 5 slice 5e) — the periods screen. Reading the ledger's calendar is ordinary
     * `ledger.view`; the close/lock/reopen buttons it hosts are gated on `ledger.period.close`
     * (catalogue ⚑) and re-authenticate through the ConfirmsRecentPassword trait on the page —
     * the same door shape the bank register uses for its ⚑ save. The Kernel Actions ask the
     * ability again, because a screen is not a permission check.
     */
    Route::livewire('accounting/periods', 'pages::accounting.periods')
        ->middleware('can:ledger.view')
        ->name('accounting.periods');

    /*
     * (Phase 5 slice 5f) — the financing book. The nav entry named `accounting.loans`
     * from the start with the loans.* permissions already in the catalogue; the routes catch up
     * now. Reading is `loans.view`; the paper moves and both money moments are the Actions'
     * `loans.manage`, re-asked at the row through LoanPolicy.
     */
    Route::livewire('accounting/loans', 'pages::accounting.loans')
        ->middleware('can:loans.view')
        ->name('accounting.loans');

    Route::livewire('accounting/loans/{loan}', 'pages::accounting.loan')
        ->middleware('can:loans.view')
        ->name('accounting.loans.show');

    Route::livewire('accounting/cash-forecast', 'pages::accounting.cash-forecast')
        ->middleware('can:loans.view')
        ->name('accounting.cash-forecast');

    /*
     * (Phase 5 slice 5a) — the supplier bills. The register reads under `accounting.view`
     * ("see expenses, bills and statements"); recording is the `bills.create` ability and the
     * Action asks again, because a screen is not a permission check.
     */
    Route::livewire('accounting/bills', 'pages::accounting.bills')
        ->middleware('can:accounting.view')
        ->name('accounting.bills');

    Route::livewire('accounting/bills/{bill}', 'pages::accounting.bill')
        ->middleware('can:accounting.view')
        ->name('accounting.bills.show');

    Route::livewire('accounting/journal/new', 'pages::accounting.journal-new')
        ->middleware([
            'can:ledger.journal.manual',
            'reauth:password.confirm,'.config('fleet.identity.reauth_window_seconds'),
        ])
        ->name('accounting.journal.create');
});
