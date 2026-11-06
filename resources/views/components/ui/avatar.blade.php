@props(['name' => null, 'initials' => null, 'size' => 'md', 'src' => null])

@php
    $letters = $initials ?? ($name ? Str::of($name)->explode(' ')->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') : '?');

    $sizes = ['sm' => 'size-7 text-2xs', 'md' => 'size-9 text-xs', 'lg' => 'size-12 text-sm'];
@endphp

<span
    {{ $attributes->class([
        'inline-grid shrink-0 select-none place-items-center overflow-hidden rounded-full border border-fg-line bg-fg-surface-sunk font-medium text-fg-ink-secondary',
        $sizes[$size] ?? $sizes['md'],
    ]) }}
    @if($name) title="{{ $name }}" aria-label="{{ $name }}" @endif
>
    @if($src)
        <img src="{{ $src }}" alt="{{ $name ?? '' }}" class="size-full object-cover" />
    @else
        {{ $letters }}
    @endif
</span>
