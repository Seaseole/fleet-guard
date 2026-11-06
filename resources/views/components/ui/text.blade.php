@props(['color' => null, 'size' => 'sm', 'as' => 'p'])

@php
    $colors = [
        null => 'text-fg-ink-secondary',
        'muted' => 'text-fg-ink-muted',
        'ink' => 'text-fg-ink',
        'accent' => 'text-accent-ink',
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];

    $sizes = ['xs' => 'text-xs', 'sm' => 'text-sm', 'base' => 'text-sm'];
@endphp

<{{ $as }} {{ $attributes->class([$sizes[$size] ?? $sizes['sm'], $colors[$color] ?? $colors[null]]) }}>
    {{ $slot }}
</{{ $as }}>
