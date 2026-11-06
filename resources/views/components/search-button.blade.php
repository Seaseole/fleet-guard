{{-- Opens the command palette; the palette itself ships in Phase 0b. --}}
<button
    type="button"
    x-on:click="$dispatch('open-palette')"
    class="hidden h-9 w-64 items-center gap-2 rounded-fg-md border border-fg-line bg-fg-surface-sunk px-3 text-start text-sm text-fg-ink-muted transition duration-(--t-fast) hover:border-fg-line-strong sm:flex"
    aria-label="{{ __('Search') }}"
    {{ $attributes }}
>
    <x-ui.icon name="magnifying-glass" size="4" />

    <span class="flex-1 truncate">{{ __('Search trips, trucks, invoices…') }}</span>

    <kbd class="rounded-fg-sm border border-fg-line bg-fg-surface px-1.5 py-0.5 font-mono text-2xs">Ctrl K</kbd>
</button>
