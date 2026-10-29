<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <x-ui.heading>{{ __('Delete account') }}</x-ui.heading>
        <x-ui.subheading>{{ __('Delete your account and all of its resources') }}</x-ui.subheading>
    </div>

    <x-ui.button
        variant="danger"
        data-test="delete-user-button"
        x-on:click="$dispatch('open-confirm-user-deletion')"
    >
        {{ __('Delete account') }}
    </x-ui.button>

    <livewire:pages::settings.delete-user-modal />
</section>
