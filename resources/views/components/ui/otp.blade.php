{{--
    Single input with wide letter-spacing rather than six boxes: it keeps the
    browser's native one-time-code autofill (autocomplete="one-time-code"), which
    matters most on a phone at a border camp.
--}}
@props([
    'name' => 'code',
    'label' => null,
    'length' => 6,
    'error' => null,
    'autocomplete' => 'one-time-code',
])

@php
    $id = $attributes->get('id') ?? $name;
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(implode(' ', array_filter([
        $attributes->get('aria-describedby'),
        $message ? $id.'-error' : null,
    ])));
@endphp

<div class="grid gap-1.5">
    @if($label)
        <label for="{{ $id }}" class="text-xs font-medium text-fg-ink-secondary">{{ $label }}</label>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="text"
        inputmode="numeric"
        autocomplete="{{ $autocomplete }}"
        maxlength="{{ $length }}"
        placeholder="{{ str_repeat('0', $length) }}"
        aria-label="{{ $label ?? __('Verification code') }}"
        @if($message) aria-invalid="true" @endif
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['id', 'name', 'aria-describedby'])->class([
            'fg-identifier w-full rounded-fg-sm border bg-fg-surface px-3 py-2 text-center text-lg tracking-[0.5em] text-fg-ink transition duration-(--t-fast)',
            'border-danger' => $message,
            'border-fg-line-strong' => ! $message,
        ]) }}
    />

    @if($message)
        <p id="{{ $id }}-error" class="flex items-center gap-1.5 text-xs text-danger" role="alert">
            <x-ui.icon name="x-circle" size="3" />
            {{ $message }}
        </p>
    @endif
</div>
