<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * §3.2's live map. The route asks for `gps.view`, which §9.3 gives to every office seat except the
 * stores clerk — the screen carries no margin, no cost and no crew detail, only where a truck was last
 * heard from.
 *
 * The ingest door itself is not here: a provider webhook is an API surface (§14.9) and lands in
 * Phase 7 with the real adapters and its own authentication. Phase 2 drives ingest through
 * `gps:simulate`, which calls the same Action.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('telematics', 'pages::telematics.live')
        ->middleware('can:gps.view')
        ->name('telematics.live');
});
