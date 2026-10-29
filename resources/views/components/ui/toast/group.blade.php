{{--
    Reads Alpine.store('fgToasts') populated by app.js from Livewire 'toast' events.
    Bottom-right, polite live region, max three stacked, 10 s when it carries an action.
--}}
<div
    aria-live="polite"
    aria-atomic="false"
    class="pointer-events-none fixed bottom-4 end-4 z-100 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
    x-data
>
    <template x-for="toast in $store.fgToasts.items" :key="toast.id">
        <div
            class="pointer-events-auto flex items-start gap-3 rounded-fg-lg border bg-fg-surface-raised px-4 py-3 text-sm shadow-fg-3"
            :class="{
                'border-success/30': toast.kind === 'success',
                'border-warning/30': toast.kind === 'warning',
                'border-danger/30': toast.kind === 'danger',
                'border-fg-line': ! toast.kind || toast.kind === 'info',
            }"
            x-transition:enter="transition duration-(--t-base) ease-(--ease-emphasis)"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-(--t-fast)"
            x-transition:leave-end="opacity-0"
        >
            <x-ui.icon name="check-circle" class="mt-0.5 text-success" x-show="toast.kind === 'success'" x-cloak />
            <x-ui.icon name="exclamation-triangle" class="mt-0.5 text-warning" x-show="toast.kind === 'warning'" x-cloak />
            <x-ui.icon name="x-circle" class="mt-0.5 text-danger" x-show="toast.kind === 'danger'" x-cloak />
            <x-ui.icon name="information-circle" class="mt-0.5 text-info" x-show="! toast.kind || toast.kind === 'info'" />

            <p class="flex-1 text-fg-ink" x-text="toast.message"></p>

            <template x-if="toast.action">
                <a :href="toast.action.href" class="font-medium text-accent hover:underline" x-text="toast.action.label"></a>
            </template>

            <button
                type="button"
                class="text-fg-ink-muted hover:text-fg-ink"
                x-on:click="$store.fgToasts.dismiss(toast.id)"
                aria-label="{{ __('Dismiss') }}"
            >
                <x-ui.icon name="x-mark" size="3" />
            </button>
        </div>
    </template>
</div>
