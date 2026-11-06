@props(['name', 'href' => '#', 'collapsed' => false, 'collapsible' => false])

{{--
    Presentational (docs/component-ui.md rule 7): the site name arrives as a prop; the shell reads
    `SiteName::current()` and passes it here, so no component in this folder touches the Domain.
--}}
<a
    href="{{ $href }}"
    class="flex min-w-0 items-center gap-2.5 rounded-fg-md text-sm font-semibold text-fg-ink transition duration-(--t-fast) hover:opacity-90"
    {{ $attributes }}
>
    <span class="grid size-8 shrink-0 place-items-center rounded-fg-md bg-accent text-on-accent">
        <x-ui.icon name="truck" size="5" />
    </span>

    @unless($collapsed)
        <span
            class="truncate"
            @if($collapsible) x-show="! collapsed" x-cloak @endif
        >{{ $name }}</span>
    @endunless
</a>
