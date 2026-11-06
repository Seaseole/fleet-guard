<?php

use Illuminate\Support\Facades\Route;

/*
 | Internal operations tool: no public marketing landing page. Unauthenticated
 | visitors go straight to the login screen; the Owner account is created by the
 | first-run setup (Phase 0) and public registration stays disabled.
 */
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * A page component rather than a static view: the overview counts rows the modules already own
     * (DESIGN-SYSTEM.md the "what needs me now"), and every figure is permission-shaped. Reads only —
     * the bands link into the screens that act.
     */
    Route::livewire('dashboard', 'pages::dashboard.dashboard')->name('dashboard');

    /*
     * The design-system showcase (docs/component-ui.md Phase 2.1): every primitive, every variant,
     * every state on one page, so a redesign pass is verified by eye against one surface rather than
     * guessed at across 88 pages. Local and staging only — production answers 404.
     */
    Route::livewire('styleguide', 'pages::styleguide')->name('styleguide');
});

require __DIR__.'/settings.php';
require __DIR__.'/security.php';
require __DIR__.'/fleet.php';
require __DIR__.'/people.php';
require __DIR__.'/operations.php';
require __DIR__.'/crossborder.php';
require __DIR__.'/telematics.php';
require __DIR__.'/fuel.php';
require __DIR__.'/kiosk.php';
require __DIR__.'/maintenance.php';
require __DIR__.'/invoicing.php';
require __DIR__.'/accounting.php';
require __DIR__.'/cutover.php';
require __DIR__.'/documents.php';
require __DIR__.'/alerts.php';
require __DIR__.'/reporting.php';
