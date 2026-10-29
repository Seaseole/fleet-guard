@props(['href' => '#', 'collapsed' => false, 'collapsible' => false])

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
        >{{ config('app.name', 'Fleet Guard') }}</span>
    @endunless
</a>
