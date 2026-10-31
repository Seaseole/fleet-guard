{{--
    Light / Dark / System, persisted for this browser by the bootstrap in partials/head.blade.php.
    Three targets so "System" stays reachable.

    The starting figure is the **effective** mode, not the stored one: a seat that never chose shows
    the company default (§20.37), and its first click makes that choice its own. "Use the company
    default" on the appearance screen hands it back.
--}}
<button
    type="button"
    x-data="{ mode: window.FleetGuardTheme?.effective() ?? 'system' }"
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
