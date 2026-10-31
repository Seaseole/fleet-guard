@props(['variant' => 'default'])

@php
    // 'segmented' is the appearance control (Light | Dark | System);
    // 'default' stacks radio rows with labels.
    $classes = $variant === 'segmented'
        ? 'inline-flex rounded-fg-md border border-input bg-fg-surface-sunk p-0.5 gap-0.5'
        : 'grid gap-2';
@endphp

<div role="radiogroup" class="{{ $classes }}" {{ $attributes }}>
    {{ $slot }}
</div>
