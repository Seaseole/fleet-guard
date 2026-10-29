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

    // Literal class names so the Tailwind v4 scanner emits them.
    $widths = [
        '40' => 'w-40', '48' => 'w-48', '56' => 'w-56', '64' => 'w-64',
        '72' => 'w-72', '80' => 'w-80', 'full' => 'w-screen',
    ];

    $panelId = 'menu-'.($name ?? Str::random(6));
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
        class="{{ $triggerClass }}"
    >
        {{ $trigger }}
    </button>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-transition:enter="transition duration-(--t-fast) ease-(--ease-standard)"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition duration-(--t-fast)"
        x-transition:leave-end="opacity-0"
        id="{{ $panelId }}"
        role="menu"
        aria-label="{{ $label }}"
        class="{{ $positions[$position] ?? $positions['bottom'] }} {{ $alignments[$align] ?? $alignments['start'] }} absolute z-50 {{ $widths[$width] ?? $widths['48'] }} max-w-[calc(100vw-2rem)] overflow-auto rounded-fg-md border border-fg-line bg-fg-surface-raised py-1 shadow-fg-2"
    >
        {{ $slot }}
    </div>
</div>
