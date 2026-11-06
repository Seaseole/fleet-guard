@props(['variant' => null, 'class' => null])

@php
    // Geometry matches the real layout so nothing jumps on arrival (DESIGN-SYSTEM.md 5).
    // A bare `class` still works for one-off sizes; the variants are what the data views use.
    $variants = [
        'card' => 'h-24 w-full rounded-fg-lg',
        'chart' => 'h-64 w-full rounded-fg-lg',
        'list' => 'grid gap-2 w-full',
        'row' => 'h-8 w-full rounded-fg-sm',
    ];

    $shape = $variant === null
        ? ($class ?? 'h-4 w-full')
        : ($variants[$variant] ?? $variants['row']);
@endphp

@if ($variant === 'list')
    <span role="status" aria-busy="true" aria-label="{{ __('Loading') }}" {{ $attributes->class(['grid gap-2', $class]) }}>
        <span class="fg-skeleton block h-8 w-full rounded-fg-sm"></span>
        <span class="fg-skeleton block h-8 w-full rounded-fg-sm"></span>
        <span class="fg-skeleton block h-8 w-2/3 rounded-fg-sm"></span>
    </span>
@elseif ($variant === 'card' || $variant === 'chart')
    <span role="status" aria-busy="true" aria-label="{{ __('Loading') }}" {{ $attributes->class(['fg-skeleton block', $shape, $class]) }}></span>
@else
    <span role="status" aria-busy="true" aria-label="{{ __('Loading') }}" {{ $attributes->class(['fg-skeleton block rounded-fg-md', $shape]) }}></span>
@endif
