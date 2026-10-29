<x-layouts::auth :title="__('Two-factor authentication')">
    <div
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            code: '',
            recovery_code: '',
            init() {
                if (! this.showRecoveryInput) {
                    this.$nextTick(() => this.$refs.code?.focus());
                }
            },
            toggleInput() {
                this.showRecoveryInput = ! this.showRecoveryInput;
                this.code = '';
                this.recovery_code = '';

                $nextTick(() => {
                    this.showRecoveryInput ? this.$refs.recovery_code?.focus() : this.$refs.code?.focus();
                });
            },
        }"
    >
        <div class="grid gap-6">
            <div x-show="! showRecoveryInput">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the authentication code provided by your authenticator application.')"
                />
            </div>

            <div x-show="showRecoveryInput" x-cloak>
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}" class="grid gap-5">
                @csrf

                <div x-show="! showRecoveryInput">
                    <x-ui.otp
                        name="code"
                        x-ref="code"
                        x-model="code"
                        :label="__('Authentication code')"
                    />
                </div>

                <div x-show="showRecoveryInput" x-cloak>
                    <x-ui.input
                        name="recovery_code"
                        x-ref="recovery_code"
                        x-bind:required="showRecoveryInput"
                        x-model="recovery_code"
                        :label="__('Recovery code')"
                        autocomplete="one-time-code"
                    />

                    @error('recovery_code')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.button variant="primary" type="submit" class="w-full">
                    {{ __('Continue') }}
                </x-ui.button>
            </form>

            <p class="text-center text-sm">
                <span class="text-fg-ink-muted">{{ __('or you can') }}</span>

                <button type="button" x-on:click="toggleInput()" class="text-accent underline underline-offset-4">
                    <span x-show="! showRecoveryInput">{{ __('login using a recovery code') }}</span>
                    <span x-show="showRecoveryInput" x-cloak>{{ __('login using an authentication code') }}</span>
                </button>
            </p>
        </div>
    </div>
</x-layouts::auth>
