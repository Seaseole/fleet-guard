@props([
    'href' => null,
    'icon' => null,
    'as' => null,
    'type' => 'button',
    'tone' => null,
    'checked' => false,
])

@php
    $tag = $as ?? ($href ? 'a' : 'button');
@endphp

<li role="none">
    <{{ $tag }}
        @if($href) href="{{ $href }}" @elseif($tag === 'button') type="{{ $type }}" @endif
        role="menuitem"
        {{ $attributes->except(['role'])->class([
            'flex w-full items-center gap-2.5 rounded-fg-sm px-2.5 py-2 text-start text-sm transition duration-(--t-fast)',
            'text-fg-ink hover:bg-fg-surface-sunk' => $tone === null,
            'text-danger hover:bg-danger-soft' => $tone === 'danger',
        ]) }}
    >
        @if($icon)
            <x-ui.icon :name="$icon" class="text-fg-ink-muted" />
        @endif

        <span class="flex-1 truncate">{{ $slot }}</span>

        @if($checked ?? false)
            <x-ui.icon name="check" class="text-accent" />
        @endif
    </{{ $tag }}>
</li>
