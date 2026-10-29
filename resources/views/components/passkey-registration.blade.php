@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (! this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="! supported">
        <x-ui.text color="muted">{{ __('Passkeys are not supported in this browser.') }}</x-ui.text>
    </template>

    <template x-if="supported && !showForm">
        <x-ui.button variant="outline" icon="plus" x-on:click="showForm = true">
            {{ __('Add passkey') }}
        </x-ui.button>
    </template>

    <template x-if="supported && showForm">
        <div class="grid gap-4 rounded-fg-md border border-fg-line bg-fg-surface-sunk p-4">
            <label class="grid gap-1.5">
                <span class="text-xs font-medium text-fg-ink-secondary">{{ __('Passkey name') }}</span>

                <input
                    type="text"
                    x-model="name"
                    x-ref="passkeyNameInput"
                    x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                    x-on:keydown.enter.prevent="register()"
                    placeholder="{{ __('e.g., MacBook Pro, iPhone') }}"
                    class="w-full rounded-fg-sm border border-fg-line-strong bg-fg-surface px-3 py-2 text-sm text-fg-ink"
                />

                <span class="text-xs text-fg-ink-muted">{{ __('Give this passkey a name to help you identify it later.') }}</span>
            </label>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-danger"></p>

            <div class="flex gap-2">
                <x-ui.button variant="primary" x-on:click="register()" x-bind:disabled="loading || !name.trim()">
                    <span x-show="! loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering…') }}</span>
                </x-ui.button>

                <x-ui.button variant="ghost" x-on:click="cancel()">{{ __('Cancel') }}</x-ui.button>
            </div>
        </div>
    </template>
</div>
