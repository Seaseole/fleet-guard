<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-ui.heading level="2" class="sr-only">{{ __('Appearance settings') }}</x-ui.heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        {{-- The preference is client-side by design: it has to apply before first
             paint, which a server round-trip cannot do (partials/head.blade.php). --}}
        <div
            x-data="{ appearance: window.FleetGuardTheme?.get() ?? 'system' }"
            x-init="$watch('appearance', (mode) => window.FleetGuardTheme?.set(mode))"
            role="radiogroup"
            aria-label="{{ __('Appearance') }}"
            class="inline-flex gap-0.5 rounded-fg-md border border-fg-line-strong bg-fg-surface-sunk p-0.5"
        >
            @foreach ([['light', 'sun', __('Light')], ['dark', 'moon', __('Dark')], ['system', 'computer-desktop', __('System')]] as [$value, $icon, $label])
                <label class="flex cursor-pointer items-center gap-2 rounded-fg-sm px-3 py-1.5 text-sm text-fg-ink-muted transition duration-(--t-fast) has-[:checked]:bg-fg-surface has-[:checked]:text-fg-ink has-[:checked]:shadow-fg-1">
                    <input type="radio" name="appearance" value="{{ $value }}" x-model="appearance" class="sr-only" />

                    <x-ui.icon :name="$icon" />

                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <x-ui.text color="muted" class="mt-4">
            {{ __('“System” follows the light or dark setting of your device.') }}
        </x-ui.text>
    </x-pages::settings.layout>
</section>
