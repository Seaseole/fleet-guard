<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Workshop screens (§3.2). `maintenance` is the register of service schedules and work orders, and
 * `maintenance/work-orders/{workOrder}` is one order's machine, lines, costs and trail.
 *
 * Both read on `maintenance.view` — §9.3 gives every seat that much, because a dispatcher asking
 * "is the truck in the workshop" and an accountant reading a repair bill are both reading. What each
 * seat may *do* is the Actions' own gates: `work_orders.create` for the workshop, `work_orders.approve`
 * for the spend, `work_orders.close` for the freeze.
 *
 * `parts` reads on `parts.view`, which is narrower than `maintenance.view`: a dispatcher may look at
 * repairs without reading what the store holds. Receiving is `parts.adjust`, counting is `parts.count`
 * — both gated in the Actions rather than by the route, so the page can print the seat's own controls.
 *
 * The detail route binds by ULID (§2), so a foreign id is a 404 rather than somebody else's work
 * order: the tenancy scope answers the lookup before `mount()` sees a row.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('maintenance', 'pages::maintenance.index')
        ->middleware('can:maintenance.view')
        ->name('maintenance.index');

    Route::livewire('maintenance/work-orders/{workOrder}', 'pages::maintenance.work-order')
        ->whereUlid('workOrder')
        ->middleware('can:maintenance.view')
        ->name('maintenance.work_order');

    Route::livewire('tyres', 'pages::maintenance.tyres')
        ->middleware('can:maintenance.view')
        ->name('maintenance.tyres');

    Route::livewire('inspections', 'pages::maintenance.inspections')
        ->middleware('can:maintenance.view')
        ->name('maintenance.inspections');

    Route::livewire('breakdowns', 'pages::maintenance.breakdowns')
        ->middleware('can:maintenance.view')
        ->name('maintenance.breakdowns');

    Route::livewire('parts', 'pages::maintenance.parts')
        ->middleware('can:parts.view')
        ->name('maintenance.parts');
});
