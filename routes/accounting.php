<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Accounting screens (§3.2). Phase 0 ships three of them: the bank and cash register, the
 * read-only journal browser, and the manual journal — the last two land here because §15.2's
 * definition of done needs "a manually posted balanced journal (and one rejected unbalanced
 * attempt)" and a person's way to see it afterwards. Period close, the trial balance and COA
 * maintenance stay in Phase 5; the ledger they read is already real.
 *
 * §9.1 draws the line between the two ledger screens. Reading the journal is an ordinary
 * permission (`ledger.view`); writing into it is a ⚑ re-auth ability
 * (PermissionCatalogue::REAUTH :: ledger.journal.manual), so the create route carries `reauth`
 * as well as `can:`. A posted entry cannot be edited or deleted (§6.3), which is precisely why
 * the door in front of it is the strictest one in the application.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('accounting/bank-accounts', 'pages::accounting.bank-accounts')
        ->middleware('can:bank.view')
        ->name('accounting.bank-accounts');

    /*
     * Suppliers are Accounting master data (§4.2: `SUPPLIER ||--o{ BILL`), shipped in Phase 1 because
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
     * §20.35 (Phase 5 slice 5a) — the supplier bills. The register reads under `accounting.view`
     * (§9.1: "see expenses, bills and statements"); recording is the `bills.create` ability and the
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
