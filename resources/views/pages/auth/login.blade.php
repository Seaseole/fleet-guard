<x-layouts::auth :title="__('Log in')">
    <div class="grid gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <x-auth-session-status :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
            @csrf

            <x-ui.input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <x-ui.input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <div class="flex items-center justify-between gap-3">
                @if (Route::has('password.request'))
                    <x-ui.link :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </x-ui.link>
                @endif

                <x-ui.checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />
            </div>

            <x-ui.button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Log in') }}
            </x-ui.button>
        </form>

        <p class="text-center text-xs text-fg-ink-muted">
            {{ __('Access is by invitation only. Contact the owner if you need an account.') }}
        </p>
    </div>
</x-layouts::auth>
