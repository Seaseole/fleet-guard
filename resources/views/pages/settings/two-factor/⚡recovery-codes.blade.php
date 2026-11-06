<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="py-6 space-y-6 border border-fg-line shadow-fg-1 rounded-fg-lg"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="px-6 space-y-2">
        <div class="flex items-center gap-2">
            <x-ui.icon name="lock-closed" class="size-4" />
            <x-ui.heading level="3">{{ __('2FA recovery codes') }}</x-ui.heading>
        </div>
        <x-ui.text>
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </x-ui.text>
    </div>

    <div class="px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-ui.button
                x-show="!showRecoveryCodes"
                icon="eye"
                variant="primary"
                x-on:click="showRecoveryCodes = true"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            >
                {{ __('View recovery codes') }}
            </x-ui.button>

            <x-ui.button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                variant="primary"
                x-on:click="showRecoveryCodes = false"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            >
                {{ __('Hide recovery codes') }}
            </x-ui.button>

            @if (filled($recoveryCodes))
                <x-ui.button
                    x-show="showRecoveryCodes"
                    icon="arrow-path"
                    variant="outline"
                    wire:click="regenerateRecoveryCodes"
                >
                    {{ __('Regenerate codes') }}
                </x-ui.button>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="mt-3 space-y-3">
                @error('recoveryCodes')
                    <x-ui.alert tone="danger">{{ $message }}</x-ui.alert>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-1 p-4 font-mono text-sm rounded-lg bg-fg-surface-sunk"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <x-ui.text class="text-xs">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                    </x-ui.text>
                @endif
            </div>
        </div>
    </div>
</div>
