@props(['level' => 2, 'size' => 'base'])

@php
    $tag = 'h'.max(1, min(6, (int) $level));

    $sizes = [
        'xs' => 'text-base leading-6 font-semibold tracking-[-0.011em]',
        'base' => 'text-xl leading-7 font-semibold tracking-[-0.011em]',
        'lg' => 'text-2xl leading-8 font-semibold tracking-[-0.015em]',
        'xl' => 'text-3xl leading-9 font-semibold tracking-[-0.015em]',
    ];
@endphp

<{{ $tag }} {{ $attributes->class(['text-fg-ink', $sizes[$size] ?? $sizes['base']]) }}>
    {{ $slot }}
</{{ $tag }}>
