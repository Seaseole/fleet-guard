{{--
    Light / Dark / System, persisted to localStorage by the bootstrap in
    partials/head.blade.php. Three targets so "System" stays reachable.
--}}
<button
    type="button"
    x-data="{ mode: window.FleetGuardTheme?.get() ?? 'system' }"
    x-on:click="
        mode = mode === 'light' ? 'dark' : (mode === 'dark' ? 'system' : 'light');
        window.FleetGuardTheme?.set(mode);
    "
    :aria-label="'Theme: ' + mode"
    class="grid size-9 place-items-center rounded-fg-sm text-fg-ink-secondary transition duration-(--t-fast) hover:bg-fg-surface-sunk hover:text-fg-ink"
    {{ $attributes }}
>
    <x-ui.icon name="sun" size="5" x-show="mode === 'light'" />
    <x-ui.icon name="moon" size="5" x-show="mode === 'dark'" x-cloak />
    <x-ui.icon name="computer-desktop" size="5" x-show="mode === 'system'" x-cloak />
</button>
