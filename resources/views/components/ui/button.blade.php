{{-- docs/DESIGN-SYSTEM.md §5: one primary button per view; destructive is filled only on the confirm step. --}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'iconTrailing' => null,
    'loading' => false,
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-fg-md font-medium transition duration-(--t-fast) ease-(--ease-standard) select-none disabled:cursor-not-allowed disabled:opacity-55';

    $variants = [
        'primary' => 'bg-accent text-on-accent hover:bg-accent-hover active:translate-y-px',
        'outline' => 'border border-fg-line-strong bg-fg-surface text-fg-ink hover:bg-fg-surface-sunk',
        'ghost' => 'text-fg-ink-secondary hover:bg-fg-surface-sunk hover:text-fg-ink',
        'danger' => 'border border-danger/40 bg-transparent text-danger hover:bg-danger-soft',
        'danger-solid' => 'bg-danger text-on-danger hover:bg-danger-hover',
    ];

    $sizes = [
        'sm' => 'h-8 px-2.5 text-xs',      /* icon-only with tooltip */
        'md' => 'h-10 px-3.5 text-sm',
        'lg' => 'h-11 px-5 text-sm',
    ];

    $tag = $href ? 'a' : 'button';
    $isDisabled = $disabled || $loading;
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if($tag === 'button' && $isDisabled) disabled @endif
    @if($tag === 'a' && $isDisabled) aria-disabled="true" tabindex="-1" @endif
    data-loading="{{ $loading ? 'true' : 'false' }}"
    {{ $attributes->class([$base, $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}
>
    @if($loading)
        <x-ui.icon name="loading" class="animate-spin" />
    @elseif($icon)
        <x-ui.icon :name="$icon" />
    @endif

    {{ $slot }}

    @if($iconTrailing)
        <x-ui.icon :name="$iconTrailing" />
    @endif
</{{ $tag }}>
