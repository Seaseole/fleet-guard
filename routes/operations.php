<?php

declare(strict_types=1);

use App\Http\Controllers\Operations\DownloadDriverLinkQrController;
use Illuminate\Support\Facades\Route;

/*
 * Operations screens. Phase 1 ships the route register and its rate cards; jobs, dispatch,
 * trips and POD arrive in Phase 2 on the same file.
 *
 * Routes and their prices are one screen because a corridor with no price on it is a plan, not an
 * offering. The route asks for `routes.view` — the widest capability any seat holds — and the rate
 * card's *amount* is behind `seeCustomerTerms` at the field layer, so a Dispatcher can file a trip
 * against GBE-WDH without being able to read what it sells for.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('routes', 'pages::operations.routes')
        ->middleware('can:routes.view')
        ->name('operations.routes');

    /*
     * The board is gated on `trips.view` rather than `trips.dispatch`: The Dispatcher seat plans and
     * dispatches, but an Accountant or Workshop should still be able to open it to answer "is that truck
     * coming back". What each seat may *do* is decided by the buttons, which ask their own permission.
     */
    Route::livewire('dispatch', 'pages::operations.dispatch')
        ->middleware('can:trips.view')
        ->name('operations.dispatch');

    /*
     * `jobs.view` opens the register; the quote's *amount* is a second question, answered by the
     * `seeCustomerTerms` field gate inside the page, which is why the Dispatcher — who may create
     * and quote a job — is not handed the figure by the route.
     */
    Route::livewire('jobs', 'pages::operations.jobs')
        ->middleware('can:jobs.view')
        ->name('operations.jobs');

    /*
     * Bound by the trip's ULID, so a foreign id is a 404 rather than somebody else's manifest: the
     * tenancy scope answers the lookup before `mount()` ever sees a row.
     */
    Route::livewire('trips/{trip}', 'pages::operations.detail')
        ->whereUlid('trip')
        ->middleware('can:trips.view')
        ->name('operations.trip');

    /*
     * The QR of a driver link. `kiosk.token.issue` rather than `trips.view`, because the file it
     * draws is the live door itself: a seat that may only read the trip must not be able to ask for the
     * code a driver scans at the gate.
     */
    Route::get('trips/{trip}/driver-link/qr.svg', DownloadDriverLinkQrController::class)
        ->whereUlid('trip')
        ->middleware('can:kiosk.token.issue')
        ->name('operations.trip.driver-link.qr');
});
