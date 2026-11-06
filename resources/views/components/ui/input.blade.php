@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'error' => null,
    'viewable' => false,
    'required' => false,
    'numeric' => false,
    'prefix' => null,
    'suffix' => null,
])

@php
    $id = $attributes->get('id') ?? $name ?? Str::random(6);
    $message = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
    $isPassword = $type === 'password' || $viewable;
    $describedBy = trim(implode(' ', array_filter([
        $attributes->get('aria-describedby'),
        $message ? $id.'-error' : ($hint ? $id.'-hint' : null),
    ])));
@endphp

<div class="grid gap-2">
    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-fg-ink-secondary">{{ $label }}</label>
    @endif

    <div class="relative flex items-center" @if($isPassword) x-data="{ shown: false }" @endif>
        @if($prefix)
            <span class="pointer-events-none absolute start-3 text-sm text-fg-ink-muted">{{ $prefix }}</span>
        @endif

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $isPassword ? 'password' : $type }}"
            @if($isPassword) x-bind:type="shown ? 'text' : 'password'" @endif
            @if($type !== 'file') value="{{ $value }}" @endif
            @if($required) required @endif
            @if($message) aria-invalid="true" @endif
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['id', 'name', 'type', 'value', 'aria-describedby'])->class([
                'h-9 w-full rounded-fg-md border bg-fg-surface px-3 text-sm touch:text-base text-fg-ink shadow-fg-1 placeholder:text-fg-ink-muted transition duration-(--t-fast) ease-(--ease-standard) disabled:cursor-not-allowed disabled:opacity-60 touch:h-11',
                'border-danger' => $message,
                'border-input hover:border-fg-ink-muted' => ! $message,
                'pe-10' => $isPassword || $suffix,
                'ps-9' => $prefix,
                'fg-numeric text-right' => $numeric,
            ])->merge([
                'autocomplete' => $isPassword ? 'current-password' : null,
                'inputmode' => $numeric ? 'decimal' : null,
            ]) }}
        />

        @if($isPassword)
            <button
                type="button"
                class="absolute end-1 touch:end-0 grid size-8 touch:size-11 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-hover hover:text-fg-ink"
                x-on:click="shown = !shown"
                aria-label="{{ __('Show password') }}"
                x-bind:aria-label="shown ? @js(__('Hide password')) : @js(__('Show password'))"
                aria-controls="{{ $id }}"
                x-bind:aria-pressed="shown"
            >
                <x-ui.icon name="eye" x-show="!shown" />
                <x-ui.icon name="eye-slash" x-show="shown" x-cloak />
            </button>
        @endif

        @if($suffix)
            <span class="pointer-events-none absolute end-3 text-sm text-fg-ink-muted">{{ $suffix }}</span>
        @endif
    </div>

    @if($message)
        <p id="{{ $id }}-error" class="flex items-center gap-1.5 text-xs text-danger" role="alert">
            <x-ui.icon name="x-circle" size="3" />
            {{ $message }}
        </p>
    @elseif($hint)
        <p id="{{ $id }}-hint" class="text-xs text-fg-ink-muted">{{ $hint }}</p>
    @endif
</div>
