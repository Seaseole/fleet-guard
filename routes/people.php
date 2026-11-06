<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * People. One register with two tabs — the people on the books, and the subset of them
 * who drive — because an employee and a driver are two rows about one person and a screen that
 * split them across two routes would leave the dispatcher guessing which one to open.
 *
 * The licence and permit dates live on the driver row; the scan of the card attaches to it as
 * a document with no expiry of its own, so the expiry board cannot report one licence twice
 * under two dates.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('people', 'pages::people.index')
        ->middleware('can:people.view')
        ->name('people.index');

    /*
     * (Phase 5 slice 5g) — the settlement desk. Reading sheets is `payroll.view`
     * (the salaries row); recording, settling and voiding are `payroll.manage`, which the
     * catalogue marks ⚑ — the settle button re-authenticates on the screen and every Action
     * asks the ability again.
     */
    Route::livewire('people/settlements', 'pages::people.settlements')
        ->middleware('can:payroll.view')
        ->name('people.settlements');
});
