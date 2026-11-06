@props(['href' => null, 'icon' => null, 'current' => false, 'trailing' => null, 'collapsibleLabel' => false, 'chevron' => false])

@php
    $link = 'group relative flex h-10 touch:h-11 items-center gap-2 rounded-fg-md px-2 text-sm transition duration-(--t-fast) ease-(--ease-standard)';
@endphp

<a
    href="{{ $href }}"
    @if($current) aria-current="page" @endif
    @if($collapsibleLabel) :class="collapsed ? 'justify-center px-0' : ''" @endif
    {{ $attributes->class([
        $link,
        'bg-accent-soft text-accent-ink font-medium' => $current,
        'text-fg-ink-secondary hover:bg-fg-surface-sunk hover:text-fg-ink' => ! $current,
    ]) }}
>
    {{-- The 2 px accent bar DESIGN-SYSTEM.md §7 documents beside the soft wash: location is carried
         by shape as well as tint, so the active item reads even where the wash is hard to see. --}}
    @if($current)
        <span aria-hidden="true" class="absolute inset-y-1 start-0 w-0.5 rounded-full bg-accent"></span>
    @endif
    @if($icon)
        <x-ui.icon :name="$icon" />
    @endif

    {{-- In the collapsed rail the label is hidden by the sidebar's own Alpine state,
         so it must not hold layout space. --}}
    <span
        @if($collapsibleLabel) x-show="! collapsed" x-cloak @endif
    >{{ $slot }}</span>

    @if($trailing)
        <span class="ms-auto text-xs text-fg-ink-muted">{{ $trailing }}</span>
    @endif

    {{-- The destination affordance a list of links otherwise lacks: a link that goes nowhere visible
         reads as a label. Kept out of the collapsed rail, where there is no room for it. --}}
    @if($chevron && ! $collapsibleLabel)
        <x-ui.icon
            name="chevron-right"
            size="4"
            class="{{ $trailing ? '' : 'ms-auto' }} text-fg-ink-muted opacity-50 transition duration-(--t-fast) ease-(--ease-standard) group-hover:opacity-100"
        />
    @endif
</a>
