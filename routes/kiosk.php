<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * §9.6's kiosk surface — the only routes in this application that are not `auth`.
 *
 * A driver has no account (ADR-0006), so the door is the trip-scoped secret in the path. Two things follow,
 * and both are load-bearing:
 *
 * - the company comes from the path, not from a user, because the tenancy guard otherwise has nothing to
 *   scope by. Guessing it achieves nothing: the secret still has to match a live token inside it;
 * - `throttle:kiosk` runs before the token lookup, so §9.6's rate limit is on the *attempt*, not on a
 *   failed comparison — a 6-digit PIN is 10^6 possibilities and an uncounted door invites the walk.
 *
 * The page is `web` (session, CSRF) but never `verified`, never MFA: a shared phone in a yard is exactly
 * the thing §9.6's "attribution survives the shared phone" sentence assumes.
 */
Route::middleware('web')->prefix('kiosk')->name('kiosk.')->group(function (): void {
    Route::livewire('{company}/{secret}', 'pages::kiosk.capture')
        ->middleware(['throttle:kiosk', 'kiosk'])
        ->whereNumber('company')
        ->name('capture');

    /*
     * §15.2's 3g: the driver's fuel screen. A separate route rather than a tab on the POD page because
     * the two are different scopes, and a link with `pod` alone must not be handed a form that can spend
     * the fleet's money. Which page a secret opens is the dispatcher's choice at issue time, and the page
     * itself still checks the scope before writing.
     */
    Route::livewire('{company}/{secret}/fuel', 'pages::kiosk.fuel')
        ->middleware(['throttle:kiosk', 'kiosk'])
        ->whereNumber('company')
        ->name('fuel');
});
