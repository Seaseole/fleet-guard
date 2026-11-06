{{--
    The textarea field. DESIGN-SYSTEM.md the design declares input, textarea and select one contract —
    `label`/`name`/`hint`/`error`, the `for`-`id` pairing derived from `name`, and the error
    replacing the hint in the same `aria-describedby` slot — but only input and select were built,
    which is why the kiosk's exceptions field hand-rolled its own with no `aria-invalid` and no
    `role="alert"` message. Mirrors `x-ui.input` deliberately, including reading `$errors` by `name`.
--}}
@props([
    'label' => null,
    'name' => null,
    'rows' => 3,
    'hint' => null,
    'error' => null,
    'required' => false,
])

@php
    $id = $attributes->get('id') ?? $name ?? Str::random(6);
    $message = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(implode(' ', array_filter([
        $attributes->get('aria-describedby'),
        $message ? $id.'-error' : ($hint ? $id.'-hint' : null),
    ])));
@endphp

<div class="grid gap-2">
    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-fg-ink-secondary">{{ $label }}</label>
    @endif

    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if($required) required @endif
        @if($message) aria-invalid="true" @endif
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['id', 'name', 'rows', 'aria-describedby'])->class([
            'w-full rounded-fg-md border bg-fg-surface px-3 py-2 text-sm touch:text-base text-fg-ink shadow-fg-1 placeholder:text-fg-ink-muted transition duration-(--t-fast) ease-(--ease-standard) disabled:cursor-not-allowed disabled:opacity-60',
            'border-danger' => $message,
            'border-input hover:border-fg-ink-muted' => ! $message,
        ]) }}
    >{{ $slot }}</textarea>

    @if($message)
        <p id="{{ $id }}-error" class="flex items-center gap-1.5 text-xs text-danger" role="alert">
            <x-ui.icon name="x-circle" size="3" />
            {{ $message }}
        </p>
    @elseif($hint)
        <p id="{{ $id }}-hint" class="text-xs text-fg-ink-muted">{{ $hint }}</p>
    @endif
</div>
