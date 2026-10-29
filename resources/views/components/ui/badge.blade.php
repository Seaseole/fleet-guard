{{--
    Status is never colour alone (DESIGN-SYSTEM.md §1.5): pass an icon and the
    caller always gets one, defaulting per tone.
--}}
@props([
    'tone' => 'neutral',
    'icon' => null,
    'size' => 'sm',
])

@php
    $tones = [
        'neutral' => ['bg' => 'bg-fg-surface-sunk text-fg-ink-secondary border-fg-line', 'icon' => 'hashtag'],
        'success' => ['bg' => 'bg-success-soft text-success border-success/25', 'icon' => 'check-circle'],
        'warning' => ['bg' => 'bg-warning-soft text-warning border-warning/25', 'icon' => 'exclamation-triangle'],
        'danger' => ['bg' => 'bg-danger-soft text-danger border-danger/25', 'icon' => 'x-circle'],
        'info' => ['bg' => 'bg-info-soft text-info border-info/25', 'icon' => 'information-circle'],
        // 'signal' rather than Heroicons' 'bolt': the registry is generated from the Flux stubs,
        // which §20.2 uninstalled, so no new glyph can be added until the artwork is vendored.
        'accent' => ['bg' => 'bg-accent-soft text-accent border-accent/25', 'icon' => 'signal'],
    ];

    $tone = $tones[$tone] ?? $tones['neutral'];

    $sizes = ['xs' => 'text-[0.6875rem] px-1.5 py-0.5 gap-1', 'sm' => 'text-xs px-2 py-0.5 gap-1.5'];
@endphp

<span
    {{ $attributes->class([
        'inline-flex items-center rounded-fg-sm border font-medium whitespace-nowrap',
        $tone['bg'],
        $sizes[$size] ?? $sizes['sm'],
    ]) }}
>
    <x-ui.icon :name="$icon ?? $tone['icon']" size="3" />

    {{ $slot }}
</span>
