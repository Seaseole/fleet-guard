<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The cross-border register. The route asks for `crossborder.view` — the widest capability any
 * seat holds (the Dispatcher has view only) — and registering a gate is decided by the button
 * behind `crossborder.manage`. What a crossing may do next is decided on the trip, not here.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('crossborder', 'pages::crossborder.index')
        ->middleware('can:crossborder.view')
        ->name('crossborder.index');
});
