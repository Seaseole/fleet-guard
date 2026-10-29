@props(['text' => null, 'position' => 'top'])

{{-- Focusable so metric definitions are keyboard-reachable (DESIGN-SYSTEM.md §10). --}}
<span
    class="relative inline-flex"
    x-data="{ shown: false }"
    x-on:mouseenter="shown = true"
    x-on:mouseleave="shown = false"
    x-on:focusin="shown = true"
    x-on:focusout="shown = false"
    tabindex="0"
    aria-describedby="{{ $text ? 'tooltip-'.md5($text) : '' }}"
    {{ $attributes->only(['class']) }}
>
    {{ $slot }}

    <span
        id="{{ $text ? 'tooltip-'.md5($text) : '' }}"
        role="tooltip"
        x-show="shown"
        x-cloak
        class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-fg-sm bg-fg-ink px-2 py-1 text-xs text-fg-canvas shadow-fg-2"
    >
        {{ $text }}
    </span>
</span>
