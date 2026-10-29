<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Security screens (§3.2): the staff register and the two halves of an invitation.
 *
 * The accept route is the only unauthenticated page in the application besides login. The token
 * travels in the path because a guest has no session to attach it to, and §9.1 makes the link itself
 * the proof of the address — which is why the row stores only its hash.
 */

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('security/users', 'pages::security.users')
        ->middleware('can:users.view')
        ->name('security.users');
    Route::livewire('security/queues', 'pages::security.queues')
        ->middleware(['can:queues.view', 'reauth:password.confirm,'.config('fleet.identity.reauth_window_seconds')])
        ->name('security.queues');
});

Route::livewire('invitations/{token}', 'pages::security.accept-invitation')
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('security.invitations.accept');
