@props(['name', 'position' => 'bottom', 'align' => 'start', 'width' => '48', 'label' => null, 'triggerClass' => '', 'collapsible' => false])

@php
    $alignments = [
        'start' => 'left-0',
        'end' => 'right-0',
        'center' => 'left-1/2 -translate-x-1/2',
    ];

    $positions = [
        'bottom' => 'top-full mt-1.5',
        'top' => 'bottom-full mb-1.5',
    ];

    /*
     * Where the panel grows from. The default transform origin is the element's own centre, which makes a
     * menu expand outward on every side at once and look detached from the button that opened it — the panel
     * is anchored to a trigger, so the edge that touches the trigger is the edge it should come out of.
     * It is a pair of keys rather than one because the vertical side decides top-versus-bottom and the
     * alignment decides which corner, and both are props on this component already.
     */
    $origins = [
        'bottom' => ['start' => 'origin-top-left', 'end' => 'origin-top-right', 'center' => 'origin-top'],
        'top' => ['start' => 'origin-bottom-left', 'end' => 'origin-bottom-right', 'center' => 'origin-bottom'],
    ];

    // Literal class names so the Tailwind v4 scanner emits them.
    $widths = [
        '40' => 'w-40', '48' => 'w-48', '56' => 'w-56', '64' => 'w-64',
        '72' => 'w-72', '80' => 'w-80', 'full' => 'w-screen',
    ];

    $panelId = 'menu-'.($name ?? Str::random(6));
    $origin = $origins[$position] ?? $origins['bottom'];
    $originClass = $origin[$align] ?? $origin['start'];

    // A panel that opens upward arrives from below its trigger, and one that opens downward from above it.
    // The offset was hard-coded to the first case, so every `position="top"` menu travelled the wrong way:
    // it slid down into place while growing away from the button, which reads as the panel falling.
    $travel = $position === 'top' ? 'translate-y-1' : '-translate-y-1';
@endphp

<div
    class="relative"
    x-data="{
        open: false,
        items: () => $refs.panel?.querySelectorAll('[role=menuitem]:not([disabled])') ?? [],
        move(offset) {
            const items = [...this.items()];
            const focused = items.indexOf(document.activeElement);
            const next = items.at(focused + offset) ?? items.at(offset > 0 ? 0 : -1);
            next?.focus();
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false; $refs.trigger?.focus()"
    x-on:keydown.arrow-down.window="open && move(1)"
    x-on:keydown.arrow-up.window="open && move(-1)"
    x-on:keydown.home.window="open && items()[0]?.focus()"
    x-on:keydown.end.window="open && items()[items().length - 1]?.focus()"
    {{ $attributes->only(['wire:key', 'id', 'class']) }}
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = !open; if (open) $nextTick(() => items()[0]?.focus())"
        x-bind:aria-expanded="open"
        aria-haspopup="menu"
        aria-controls="{{ $panelId }}"
        @if($collapsible) x-bind:class="collapsed ? 'justify-center px-0' : ''" @endif
        {{ $attributes->except(['wire:key', 'id', 'class']) }}
        class="{{ $triggerClass }} transition-[color,background-color,border-color,box-shadow,opacity,transform,translate,scale] duration-(--t-fast) ease-(--ease-standard) active:scale-[0.97]"
    >
        {{ $trigger }}
    </button>

    {{--
        Opening is the moment the seat is watching, so it gets the room to be read: --t-base with the strong
        entering curve, growing from the trigger's edge. Closing is a response to a decision already made —
        the menu is gone the instant they press Escape or pick a row — so it takes --t-fast and no travel,
        shrinking a hair toward the same edge. The exit is deliberately faster than the entry; the two being
        equal is what makes a menu feel like it is waiting for you.

        `scale-[0.96]` rather than a plain fade: at this size a panel that only changes opacity reads as a
        repaint of the region instead of an object arriving from the button. It is not `scale(0)` under any
        condition — nothing appears out of nothing, and a shape at 96 % already has an edge.
    --}}
    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-transition:enter="transition duration-(--t-base) ease-(--ease-enter) {{ $originClass }}"
        x-transition:enter-start="opacity-0 scale-[0.96] {{ $travel }}"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition duration-(--t-fast) ease-(--ease-standard) {{ $originClass }}"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.98]"
        id="{{ $panelId }}"
        role="menu"
        aria-label="{{ $label }}"
        class="{{ $positions[$position] ?? $positions['bottom'] }} {{ $alignments[$align] ?? $alignments['start'] }} absolute z-(--z-menu) {{ $widths[$width] ?? $widths['48'] }} max-w-[calc(100vw-2rem)] overflow-auto rounded-fg-md border border-fg-line bg-fg-surface-raised py-1 shadow-fg-2 {{ $originClass }}"
    >
        {{ $slot }}
    </div>
</div>
