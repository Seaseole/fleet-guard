<?php

use App\Domain\Kernel\Settings\Theme;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    public string $companyTheme = '';

    public function mount(): void
    {
        // Read at render, not baked into the markup: the Owner may have changed it on the Application
        // settings screen since this page was compiled (§20.37).
        $this->companyTheme = Theme::defaultMode();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-ui.heading level="2" class="sr-only">{{ __('Appearance settings') }}</x-ui.heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('What this app looks like on this device')">
        {{-- Two layers, deliberately: this screen is a personal choice kept in the browser so it can
             apply before first paint (partials/head.blade.php), while the theme everyone opens in is
             the company's own setting under Application settings (§20.37). --}}
        <div
            x-data="{
                appearance: window.FleetGuardTheme?.effective() ?? 'system',
                personal: window.FleetGuardTheme?.isPersonal() ?? false,
            }"
            x-init="$watch('appearance', (mode) => { window.FleetGuardTheme?.set(mode); personal = true; })"
            class="grid gap-4"
        >
            <div
                role="radiogroup"
                aria-label="{{ __('Appearance') }}"
                class="inline-flex gap-0.5 self-start rounded-fg-md border border-fg-line-strong bg-fg-surface-sunk p-0.5"
            >
                @foreach ([['light', 'sun', __('Light')], ['dark', 'moon', __('Dark')], ['system', 'computer-desktop', __('System')]] as [$value, $icon, $label])
                    <label class="flex cursor-pointer items-center gap-2 rounded-fg-sm px-3 py-1.5 text-sm text-fg-ink-muted transition duration-(--t-fast) has-[:checked]:bg-fg-surface has-[:checked]:text-fg-ink has-[:checked]:shadow-fg-1">
                        <input type="radio" name="appearance" value="{{ $value }}" x-model="appearance" class="sr-only" />

                        <x-ui.icon :name="$icon" />

                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>

            <p class="text-sm text-fg-ink-muted">
                {{ __('“System” follows the light or dark setting of your device.') }}
                {{ __('Everyone in :company opens in :theme unless they change it here.', [
                    'company' => App\Domain\Kernel\Tenancy\SiteName::current(),
                    'theme' => ucfirst($companyTheme),
                ]) }}
            </p>

            <x-ui.button
                variant="ghost"
                size="sm"
                type="button"
                class="self-start"
                x-on:click="window.FleetGuardTheme?.clear(); appearance = window.FleetGuardTheme.effective(); personal = false;"
                x-show="personal"
                x-cloak
            >{{ __('Use the company default again') }}</x-ui.button>
        </div>
    </x-pages::settings.layout>
</section>
