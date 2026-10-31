@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'error' => null,
    'options' => null,
    'placeholder' => null,
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

{{--
    The select field, so the choice is styled in one place.

    Sixty-four of these were hand-copied `<select class="w-full rounded-fg-sm border …">` blocks across
    eighteen pages, each re-declaring the border, the focus treatment and the error state — which is how
    `x-ui.input`'s `aria-invalid`, `aria-describedby` and `role="alert"` message had no equivalent here: a
    refused selection was a value that silently did not change. The `class` strings are now the component's
    business, and `scripts/lint-ui.php` rule U2 refuses a raw `<select>` in a page.

    Mirrors `x-ui.input` deliberately, including reading `$errors` by `name`: an existing `<label for="…">`
    still points at the forwarded `id`, so converting a field does not require rewriting its label.
--}}
<div class="grid gap-2">
    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-fg-ink-secondary">{{ $label }}</label>
    @endif

    <div class="relative">
        <select
            id="{{ $id }}"
            name="{{ $name }}"
            @if($required) required @endif
            @if($message) aria-invalid="true" @endif
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['id', 'name', 'aria-describedby'])->class([
                'h-9 w-full appearance-none rounded-fg-md border bg-fg-surface ps-3 pe-9 text-sm text-fg-ink shadow-fg-1 transition duration-(--t-fast) ease-(--ease-standard) disabled:cursor-not-allowed disabled:opacity-60',
                'border-danger' => $message,
                'border-input hover:border-fg-ink-muted' => ! $message,
            ]) }}
        >
            @if($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif

            @if(is_array($options))
                @foreach($options as $value => $optionLabel)
                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                @endforeach
            @endif

            {{ $slot }}
        </select>

        <x-ui.icon name="chevron-down" size="4" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-ink-muted" />
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
