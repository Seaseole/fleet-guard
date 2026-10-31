@props([
    'name' => null,
    'label' => null,
    'checked' => false,
    'value' => '1',
])

@php $id = $attributes->get('id') ?? $name ?? 'checkbox-'.Str::random(6); @endphp

<label for="{{ $id }}" class="flex cursor-pointer items-center gap-2 text-sm text-fg-ink">
    <input
        id="{{ $id }}"
        type="checkbox"
        name="{{ $name }}"
        value="{{ $value }}"
        @if($checked) checked @endif
        class="size-4 shrink-0 rounded border border-input bg-fg-surface accent-accent shadow-fg-1"
        {{ $attributes->except(['id','name','value']) }}
    />

    @if($label)
        <span>{{ $label }}</span>
    @endif

    {{ $slot }}
</label>
