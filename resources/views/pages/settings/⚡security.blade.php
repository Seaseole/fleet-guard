<?php

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
/* @chisel-passkeys */
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
/* @end-chisel-passkeys */
/* @chisel-2fa */
use Livewire\Component;

/* @end-chisel-2fa */

new #[Title('Security settings')] class extends Component
{
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /* @chisel-2fa */
    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;
    /* @end-chisel-2fa */

    /* @chisel-passkeys */
    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';
    /* @end-chisel-passkeys */

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /* @chisel-2fa */
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        /* @chisel-passkeys */
        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
        /* @end-chisel-passkeys */
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('toast', message: __('Password updated.'), kind: 'success');
    }

    /* @chisel-passkeys */
    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->dispatch('open-delete-passkey-modal');
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->dispatch('close-delete-passkey-modal');
        $this->loadPasskeys();
    }

    /**
     * Clear the delete confirmation after the dialog closes.
     */
    public function closeDeleteModal(): void
    {
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }
    /* @end-chisel-passkeys */

    /* @chisel-2fa */
    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        abort_if(auth()->user()->requiresMfa(), 403, 'Two-factor authentication is required for your role.');
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
    /* @end-chisel-2fa */
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-ui.heading level="2" class="sr-only">{{ __('Security settings') }}</x-ui.heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <x-ui.input
                wire:model="current_password"
                name="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <x-ui.input
                wire:model="password"
                name="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <x-ui.input
                wire:model="password_confirmation"
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                {{-- Outline, not primary: The design makes the factor the affirmative action of this screen, so
                     the two-weight rule resolves in its favour and a password change stays a secondary
                     form. --}}
                <x-ui.button variant="outline" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        {{-- @chisel-2fa --}}
        @if ($canManageTwoFactor)
            <section class="mt-12">
                <x-ui.heading>{{ __('Two-factor authentication') }}</x-ui.heading>
                <x-ui.subheading>{{ __('Manage your two-factor authentication settings') }}</x-ui.subheading>

                @if (auth()->user()->requiresMfa() && ! $twoFactorEnabled)
                    <div class="mt-6 flex items-start gap-3 rounded-lg border border-warning/30 bg-warning-soft p-4 text-sm" role="status">
                        <x-ui.icon name="shield-check" class="size-5 shrink-0 text-warning" />
                        <div class="space-y-1">
                            <p class="font-medium text-fg-ink">{{ __('Two-factor authentication is required for your role') }}</p>
                            <p class="text-fg-ink-secondary">
                                {{ __('Your seat can see or move money, so a confirmed authenticator app is mandatory. Enable 2FA below — until you do, the rest of Fleet Guard stays locked.') }}
                            </p>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <x-ui.text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </x-ui.text>

                            @if (auth()->user()->requiresMfa())
                                <x-ui.text>{{ __('Two-factor authentication is required for your role and cannot be disabled.') }}</x-ui.text>
                            @else
                                <div class="flex justify-start">
                                    <x-ui.button variant="danger" wire:click="disable">
                                        {{ __('Disable 2FA') }}
                                    </x-ui.button>
                                </div>
                            @endif

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <x-ui.text>
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </x-ui.text>

                            <x-ui.button
                                variant="primary"
                                wire:click="$dispatch('start-two-factor-setup')"
                                x-on:click="$dispatch('open-two-factor-setup-modal')"
                            >
                                {{ __('Enable 2FA') }}
                            </x-ui.button>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif
        {{-- @end-chisel-2fa --}}

        {{-- @chisel-passkeys --}}
        @if ($canManagePasskeys)
            <section class="mt-12">
                <x-ui.heading>{{ __('Passkeys') }}</x-ui.heading>
                <x-ui.subheading>{{ __('Manage your passkeys for passwordless sign-in') }}</x-ui.subheading>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="border rounded-lg border-fg-line overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-fg-line' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-fg-md bg-fg-surface-sunk">
                                        <x-ui.icon name="key" class="size-5 text-fg-ink-muted"  />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <x-ui.badge size="sm">{{ $passkey['authenticator'] }}</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="text-fg-ink-muted text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <x-ui.button
                                    variant="danger"
                                    size="sm"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-fg-lg bg-fg-surface-sunk">
                                    <x-ui.icon name="key" class="size-7 text-fg-ink-muted"  />
                                </div>
                                <p class="font-medium">{{ __('No passkeys yet') }}</p>
                                <x-ui.text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</x-ui.text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
        {{-- @end-chisel-passkeys --}}
    </x-pages::settings.layout>

    {{-- @chisel-passkeys --}}
    <x-ui.dialog
        name="delete-passkey-modal"
        maxWidth="md"
        class="max-w-md md:min-w-md"
        aria-labelledby="delete-passkey-modal-title"
        x-on:dialog-closed="$wire.closeDeleteModal()"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <x-ui.heading id="delete-passkey-modal-title">{{ __('Remove passkey') }}</x-ui.heading>
                <x-ui.text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </x-ui.text>
            </div>

            <div class="flex gap-3 justify-end">
                <x-ui.button
                    variant="outline"
                    type="button"
                    x-on:click="$dispatch('close-delete-passkey-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.dialog>
    {{-- @end-chisel-passkeys --}}
</section>
