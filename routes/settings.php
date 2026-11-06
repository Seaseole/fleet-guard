<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    // The device list, own-scope: everyone sees and revokes the devices carrying *their* credential,
    // and nobody is handed another person's session rows. The administrative control for "stop this
    // person" is deactivating their account, not this screen.
    Route::livewire('settings/sessions', 'pages::settings.sessions')->name('sessions.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    /*
     * The Settings row, application scope. It is company-held policy, not a per-person
     * preference, which is why it lives at its own URL rather than in the personal list beside it.
     * The row is ⚑, so the route asks for the password again within the window; the page itself
     * refuses a save without `settings.manage` (Owner), while Operations and Accounting may read it.
     */
    Route::redirect('settings/company', '/settings/application')
        ->middleware(['can:settings.view']);

    Route::livewire('settings/application', 'pages::settings.application')
        ->middleware([
            'can:settings.view',
            'reauth:password.confirm,'.config('fleet.identity.reauth_window_seconds'),
        ])
        ->name('application.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
