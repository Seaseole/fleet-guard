@props([
    'value',
    'label' => null,
    'icon' => null,
    'variant' => 'default',
])

@php
    $id = $attributes->get('id') ?? 'radio-'.Str::random(6);

    $segmented = 'flex items-center gap-2 rounded-fg-sm px-3 py-1.5 text-sm cursor-pointer transition duration-(--t-fast) has-[:checked]:bg-fg-surface has-[:checked]:text-fg-ink has-[:checked]:shadow-fg-1 text-fg-ink-muted';
    $row = 'flex items-center gap-2.5 text-sm text-fg-ink cursor-pointer';
@endphp

<label for="{{ $id }}" class="{{ $variant === 'segmented' ? $segmented : $row }}">
    <input
        type="radio"
        id="{{ $id }}"
        value="{{ $value }}"
        class="size-4 touch:size-6 accent-accent"
        {{ $attributes->except(['id','value','class']) }}
    />

    @if($icon)
        <x-ui.icon :name="$icon" />
    @endif

    @if($label)
        <span>{{ $label }}</span>
    @endif

    {{ $slot }}
</label>
