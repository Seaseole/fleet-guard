<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Fleet. One register with three tabs and one detail screen per truck.
 *
 * The register route asks for `fleet.view` and nothing more: reading the fleet is the widest
 * capability any seat holds (a Dispatcher names a truck on a trip before they may change it), and the
 * row-level write checks live in the policies the Actions ask, not in a route that would have to be
 * duplicated for every button on the page.
 *
 * The detail route binds the truck by id. Livewire resolves `{truck}` through Laravel's implicit
 * binding, so `mount(Truck $truck)` receives a row that already went through the tenancy scope — a
 * truck id from another company is a 404, not a screen that renders somebody else's odometer.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('fleet', 'pages::fleet.index')
        ->middleware('can:fleet.view')
        ->name('fleet.index');

    Route::livewire('fleet/trucks/{truck}', 'pages::fleet.vehicle')
        ->whereNumber('truck')
        ->middleware('can:fleet.view')
        ->name('fleet.show');
});
