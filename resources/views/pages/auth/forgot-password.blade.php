<x-layouts::auth :title="__('Forgot password')">
    <div class="grid gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-auth-session-status :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="grid gap-5">
            @csrf

            <x-ui.input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <x-ui.button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </x-ui.button>
        </form>

        <p class="text-center text-sm text-fg-ink-muted">
            {{ __('Or, return to') }}
            <x-ui.link :href="route('login')" wire:navigate>{{ __('log in') }}</x-ui.link>
        </p>
    </div>
</x-layouts::auth>
