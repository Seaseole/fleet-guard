<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The workshop fuel register. `fuel.view` is what the Accountant reads with; the pump card,
 * the station and the tank are all `fuel.record` writes, refused by the Actions themselves.
 *
 * Issuing fuel is not here: an issue belongs to a trip, and it is recorded on the trip's own fuel
 * panel where the truck, the tank and the trip cost are one screen.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('fuel', 'pages::fuel.index')
        ->middleware('can:fuel.view')
        ->name('fuel.index');
});
