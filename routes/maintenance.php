<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Workshop screens (§3.2). Phase 1 ships the parts catalogue; schedules, work orders, tyres and
 * inspections arrive in phase 3 on this file.
 *
 * The catalogue asks for `parts.view` — the Workshop Manager and Stores Clerk maintain it, and the
 * Operations Manager and Accountant read it when a repair bill arrives.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('parts', 'pages::maintenance.parts')
        ->middleware('can:parts.view')
        ->name('maintenance.parts');
});
