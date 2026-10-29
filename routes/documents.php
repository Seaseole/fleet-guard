<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\DownloadDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * Documents (§14.4). Two screens and one endpoint, and the endpoint is the reason the rest exists.
 *
 * The download route is the only place in the application that reads the private disk, which is what
 * makes §14.4's promises enforceable rather than stylistic: a file that could also be fetched by a
 * public path has no download trail, no scan gate and no TTL, because none of those are on its way
 * out. `serve => false` on the disk is what stops the framework offering a second door.
 *
 * `signed` is on the route rather than checked in the controller so the TTL is enforced by the
 * middleware that implements it — a reviewer reading the route table sees the guarantee, and a
 * future second download route cannot forget to call it.
 *
 * The policy check is *also* in the controller, and that is not duplication: a signature proves the
 * URL came from this application, never that the browser holding it is still the session it was
 * minted for. Sixty seconds is short, and "signed" and "authorised" are different questions.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('documents', 'pages::documents.documents')
        ->middleware('can:documents.view')
        ->name('documents.index');

    Route::livewire('documents/register', 'pages::documents.register')
        ->middleware('can:documents.upload')
        ->name('documents.register');

    Route::get('documents/versions/{documentVersion}/download', DownloadDocumentController::class)
        ->middleware('signed')
        ->name('documents.download');
});
