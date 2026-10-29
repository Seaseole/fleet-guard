<x-layouts::auth :title="__('Email verification')">
    <div class="grid gap-6">
        <x-ui.text class="text-center">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </x-ui.text>

        @if (session('status') == 'verification-link-sent')
            <x-ui.alert tone="success">
                {{ __('A new verification link has been sent to the email address you provided.') }}
            </x-ui.alert>
        @endif

        <div class="grid gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-ui.button type="submit" variant="primary" class="w-full">
                    {{ __('Resend verification email') }}
                </x-ui.button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.button variant="ghost" type="submit" class="w-full cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </x-ui.button>
            </form>
        </div>
    </div>
</x-layouts::auth>
