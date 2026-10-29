<?php

declare(strict_types=1);

use App\Http\Controllers\Cutover\DownloadCutoverErrorsController;
use Illuminate\Support\Facades\Route;

/*
 * §15.2 Phase 0.5 — legacy intake and the opening-balance rehearsal.
 *
 * One screen serves both halves of the workflow on purpose: upload, validate and preview are the
 * accountant's work (`cutover.import`), while posting and reversing are the Owner's
 * (`cutover.post`). The route only requires the import permission; the component asks the policy
 * for `post`/`reverse` and requires a recent password confirmation before either, so validation
 * never forces a re-authentication the accountant did not need.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('accounting/cutover', 'pages::accounting.cutover')
        ->middleware('can:cutover.import')
        ->name('accounting.cutover');

    // The error report is the deliverable, so it is a real URL: the browser downloads it and the
    // accountant can be sent the link without being shown the screen that produced it.
    Route::get('accounting/cutover/{batch}/errors.csv', DownloadCutoverErrorsController::class)
        ->middleware('can:cutover.import')
        ->name('accounting.cutover.errors');
});
