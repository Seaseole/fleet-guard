<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The alert register — one screen, because one table is the source of truth for "needs
 * attention".
 *
 * There is no separate show/edit route: every action on an alert is a state transition, and the state
 * machine is small enough that the register that shows the row is the screen that should be able
 * to move it. Splitting them would put the reason field on a page the operator has to navigate to, and
 * The rule is that the reason has to be as close to the decision as possible.
 *
 * `alerts.view` opens the screen. The writes split in two, on the machine's own boundary:
 * `alerts.acknowledge` for acknowledge and mute — reversible, and held by the roles the design delivers to —
 * and `alerts.resolve` for the transition `alerts_resolved_is_final` makes irreversible. A single
 * permission for both would have to be granted at the level a dispatcher needs and would then let that
 * dispatcher close a compliance finding for good.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('alerts', 'pages::alerts.alerts')
        ->middleware('can:alerts.view')
        ->name('alerts.index');
});
