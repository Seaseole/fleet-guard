@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'error' => null,
    'options' => null,
    'placeholder' => null,
    'required' => false,
    'widget' => true,
    'optionKey' => null,
    'template' => null,
    'templateSelection' => null,
])

@php
    $id = $attributes->get('id') ?? $name ?? Str::random(6);
    $message = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
    $describedBy = trim(implode(' ', array_filter([
        $attributes->get('aria-describedby'),
        $message ? $id.'-error' : ($hint ? $id.'-hint' : null),
    ])));

    // Clearing is offered where the field is genuinely blankable — it prints a placeholder and is not
    // required — and never on a required field, so the widget does not grant a way to undo a choice the
    // server would refuse.
    $clear = $placeholder !== null && ! $required;

    $widgetOptions = array_filter([
        // `theme` renames select2's own theme class on both the trigger and the panel, which is what keeps
        // the library's `--default` cosmetics from ever matching. See resources/css/select2-theme.css.
        'theme' => 'fg',
        // A search box under four statuses is noise, so the threshold is one number here rather than a
        // prop on 148 call sites. select2 counts its own results, so no page has to declare its size.
        'minimumResultsForSearch' => 8,
        'placeholder' => $placeholder,
        // `data-template` names one function for both the open row and the closed trigger, but the two
        // want different density: a row can spend a second line on why a driver's papers fail, the 36px
        // box cannot. This overrides only the closed state, which is what select2 merges last.
        'templateSelection' => $templateSelection,
    ], fn ($value) => $value !== null);
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

    The widget replaces the whole branch below, and it is deliberately invisible to the 148 pages that call
    this component: the mount, the panel's parent and the search threshold are the component's business too.

    `wire:ignore` freezes the subtree that select2 owns, because a morph would delete the panel the library
    built and re-mount it closed on every keystroke. Two consequences are handled here rather than by every
    page. The error class goes on the wrapper — attributes on the ignored element itself still morph, while
    `aria-invalid` on the hidden `<select>` would not — and `optionKey` gives a list whose OPTIONS change
    with a parent field a key that changes with it, which is the one thing a frozen subtree cannot notice.

    `:widget="false"` keeps the native control. The driver kiosk uses it: a tablet's own picker needs no
    download and no keyboard, and the whole jQuery budget stays off the page.
--}}
<div class="grid gap-2">
    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-fg-ink-secondary">{{ $label }}</label>
    @endif

    <div
        @if($widget)
            wire:ignore
            @if($optionKey !== null) wire:key="select-{{ $id }}-{{ $optionKey }}" @endif
        @endif
        @class(['relative', 'fg-select--error' => $message !== null && $message !== false && $message !== ''])
    >
        <select
            id="{{ $id }}"
            name="{{ $name }}"
            @if($required) required @endif
            @if($message) aria-invalid="true" @endif
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if($widget)
                data-pharaonic="select2"
                {{-- With no value this resolves to the nearest dialog and falls back to the body outside
                     one; the panel has to be inside a native <dialog> or the top layer paints over it. --}}
                data-parent
                data-clear="{{ $clear ? 'true' : 'false' }}"
                data-options='@json($widgetOptions)'
                @if($template !== null) data-template="{{ $template }}" @endif
            @endif
            {{ $attributes->except(['id', 'name', 'aria-describedby'])->class([
                'h-9 w-full appearance-none rounded-fg-md border bg-fg-surface ps-3 pe-9 text-sm touch:text-base text-fg-ink shadow-fg-1 transition duration-(--t-fast) ease-(--ease-standard) disabled:cursor-not-allowed disabled:opacity-60 touch:h-11',
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

        @if(! $widget)
            <x-ui.icon name="chevron-down" size="4" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-ink-muted" />
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
