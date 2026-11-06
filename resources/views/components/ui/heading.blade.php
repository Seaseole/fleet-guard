@props(['level' => 2, 'size' => 'base'])

@php
    $tag = 'h'.max(1, min(6, (int) $level));

    $sizes = [
        'xs' => 'text-base leading-6 font-semibold tracking-tight',
        'base' => 'text-xl leading-7 font-semibold tracking-tight',
        'lg' => 'text-2xl leading-8 font-semibold tracking-figure',
        'xl' => 'font-sans text-2xl leading-8 font-semibold tracking-tight',
    ];
@endphp

<{{ $tag }} {{ $attributes->class(['text-fg-ink', $sizes[$size] ?? $sizes['base']]) }}>
    {{ $slot }}
</{{ $tag }}>
