@props(['text' => null, 'position' => 'top'])

@php
    // The position prop used to be decorative — every bubble rendered above whatever it described,
    // so a tooltip near the top of a register was clipped by the sticky header. Each side gets its
    // own anchor and its own offset; `left`/`right` centre vertically instead of horizontally.
    //
    // The `origin-*` beside each anchor is what makes the bubble belong to its trigger. A tooltip that
    // scales from its own centre grows outward on two sides at once and looks detached from the thing
    // it describes; grown from the edge that touches the trigger, it reads as that edge extending. The
    // origin is a per-position value rather than one rule because the touching edge is a different edge
    // on every side — and unlike a popover, there is no Radix variable here to read it from.
    $positions = [
        'top' => 'bottom-full left-1/2 -translate-x-1/2 mb-1.5 origin-bottom',
        'bottom' => 'top-full left-1/2 -translate-x-1/2 mt-1.5 origin-top',
        'left' => 'right-full top-1/2 -translate-y-1/2 mr-1.5 origin-right',
        'right' => 'left-full top-1/2 -translate-y-1/2 ml-1.5 origin-left',
    ];
@endphp

{{-- Focusable so metric definitions are keyboard-reachable. --}}
<span
    class="relative inline-flex"
    x-data="{
        shown: false,
        instant: false,
        timer: null,
        open() {
            // The delay exists to stop a bubble firing when a cursor merely crosses the control, so it
            // can only ever apply to the first one. Once a bubble has been up in the last second and a
            // half, the seat is sweeping the rail and already knows what these are — showing the next
            // one on a timer makes them wait for something they have already learned. `fgTooltip.last`
            // is one timestamp shared by every bubble in the app, because the second tooltip's sense of
            // urgency is created by the first one, which is a different component.
            this.instant = $store.fgTooltip.last > Date.now() - 1500;

            if (this.instant) {
                this.show();

                return;
            }

            this.timer = setTimeout(() => this.show(), 400);
        },
        show() {
            this.shown = true;
            $store.fgTooltip.last = Date.now();
        },
        close() {
            clearTimeout(this.timer);
            this.shown = false;
        },
    }"
    x-on:mouseenter="open()"
    x-on:mouseleave="close()"
    x-on:focusin="open()"
    x-on:focusout="close()"
    tabindex="0"
    aria-describedby="{{ $text ? 'tooltip-'.md5($text) : '' }}"
    {{ $attributes->only(['class']) }}
>
    {{ $slot }}

    {{-- `transition-none` is chosen over the transition classes rather than layered on top of them:
         Alpine applies exactly the string it is handed, so there is no tie between two utilities of
         equal specificity for the built sheet's source order to break. --}}
    <span
        id="{{ $text ? 'tooltip-'.md5($text) : '' }}"
        role="tooltip"
        x-show="shown"
        x-cloak
        x-transition:enter="instant ? 'transition-none' : 'transition duration-(--t-fast) ease-(--ease-enter)'"
        x-transition:enter-start="opacity-0 scale-[0.97]"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition duration-(--t-fast) ease-(--ease-standard)"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.97]"
        class="pointer-events-none absolute {{ $positions[$position] ?? $positions['top'] }} z-(--z-menu) whitespace-nowrap rounded-fg-sm bg-fg-ink px-2 py-1 text-xs text-fg-canvas shadow-fg-2"
    >
        {{ $text }}
    </span>
</span>
