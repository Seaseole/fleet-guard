<?php

declare(strict_types=1);

use App\Http\Controllers\Reporting\DownloadReportExport;
use Illuminate\Support\Facades\Route;

/*
 * The report library. The nav has reserved `reports.index` under `reports.view`
 * since Phase 0; this is the screen that earns it.
 *
 * Splits the library from its outputs: reading is `reports.view` plus each report's own
 * gate (the catalogue carries them, the screen asks them), while every export additionally asks
 * its category's export ability — and `reports.export.financial` is on the PermissionCatalogue's
 * ⚑ re-auth list, because "an export is an authorised read, not a side door".
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('reports', 'pages::reporting.index')
        ->middleware('can:reports.view')
        ->name('reports.index');

    /*
     * A finished export leaves the building through one action that re-asks every gate
     * the queue step asked, streams private no-store, and writes its trail row before the first
     * byte. The financial half additionally runs Fortify's password confirmation inside the
     * action (the ⚑ attaches per report, so it cannot live as static route middleware).
     */
    Route::get('reports/exports/{export}/download', DownloadReportExport::class)
        ->name('reports.exports.download');
});
