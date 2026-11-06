{{--
    Reads Alpine.store('fgToasts') populated by app.js from Livewire 'toast' events.
    Bottom-right, polite live region, max three stacked, 10 s when it carries an action.
--}}
<div
    aria-live="polite"
    aria-atomic="false"
    class="pointer-events-none fixed bottom-4 end-4 z-(--z-toast) flex w-[min(24rem,calc(100vw-2rem))] flex-col"
    x-data
>
    {{--
        The stack is the reason this transition is longer than a menu's. A toast that only fades appears and
        disappears in place while its neighbours jump up or down by its full height in one frame — which is
        the single moment the stack stops reading as a stack of objects and starts reading as repaints. So
        the height collapses too, and the neighbours move instead of teleporting.

        That makes this the one seam in the app that animates a property outside `transform` and `opacity`,
        and it is the exception rather than a lapse: a list whose items come and go has to relayout, and the
        choice is between an animated relayout of four elements and a jarring one. It is bounded — the
        container holds at most three toasts and each is a line of text with a button — and the reduced-motion
        block in app.css drops `max-height` and `margin` from every transitioned property list, so a seat that
        asked for less motion gets the collapse instantly and never sees the animation at all.

        `overflow-hidden` lives on the transition classes and not on the element: the steady state must not
        clip, because `shadow-fg-3` is the only thing separating a toast from the register behind it.

        Enter and exit travel the same way — up into the stack, down out of it, both from below the fold of
        the corner they sit in. Direction is what makes a swipe-away or a press of the X feel like the same
        gesture in reverse rather than a different event.
    --}}
    <template x-for="toast in $store.fgToasts.items" :key="toast.id">
        <div
            class="pointer-events-auto mb-2 flex max-h-40 items-start gap-3 rounded-fg-lg border bg-fg-surface-raised px-4 py-3 text-sm shadow-fg-3"
            :class="{
                'border-success/30': toast.kind === 'success',
                'border-warning/30': toast.kind === 'warning',
                'border-danger/30': toast.kind === 'danger',
                'border-fg-line': ! toast.kind || toast.kind === 'info',
            }"
            x-transition:enter="transition duration-(--t-base) ease-(--ease-enter)"
            x-transition:enter-start="opacity-0 translate-y-3"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-(--t-fast) ease-(--ease-standard)"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
        >
            <x-ui.icon name="check-circle" class="mt-0.5 text-success" x-show="toast.kind === 'success'" x-cloak />
            <x-ui.icon name="exclamation-triangle" class="mt-0.5 text-warning" x-show="toast.kind === 'warning'" x-cloak />
            <x-ui.icon name="x-circle" class="mt-0.5 text-danger" x-show="toast.kind === 'danger'" x-cloak />
            <x-ui.icon name="information-circle" class="mt-0.5 text-info" x-show="! toast.kind || toast.kind === 'info'" />

            <p class="flex-1 text-fg-ink" x-text="toast.message"></p>

            <template x-if="toast.action">
                <a :href="toast.action.href" class="font-medium text-accent-ink hover:underline" x-text="toast.action.label"></a>
            </template>

            <button
                type="button"
                class="text-fg-ink-muted transition duration-(--t-fast) ease-(--ease-standard) hover:text-fg-ink active:scale-[0.9]"
                x-on:click="$store.fgToasts.dismiss(toast.id)"
                aria-label="{{ __('Dismiss') }}"
            >
                <x-ui.icon name="x-mark" size="3" />
            </button>
        </div>
    </template>
</div>
