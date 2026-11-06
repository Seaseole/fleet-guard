@props([
    'name' => null,
    'label' => null,
    'checked' => false,
    'value' => '1',
])

@php $id = $attributes->get('id') ?? $name ?? 'checkbox-'.Str::random(6); @endphp

{{--
    The tick is drawn here rather than left to the browser, because `accent-color` cannot animate: the
    native check appears between two painted frames, so the one control a seat uses to acknowledge a
    compliance item, a bill line or a bulk selection is the one control in the app that gave no answer to
    being switched. `appearance-none` is what makes a drawn tick possible, and the real `<input>` stays the
    input — it is what carries `wire:model`, the checked state, the keyboard focus and the global
    `:focus-visible` band in app.css. Only its paint moved.

    The tick scales in from `0.8`, not from nothing. A mark that grows from a point reads as arriving; one
    that switches between absent and full size reads as a repaint.

    `peer-checked` is on the wrapper and not on the icon: Tailwind compiles it to a sibling combinator, so
    only an element that is a sibling of the input can react to it. Opacity and scale both apply to a
    parent as a group, so the icon nested inside inherits the effect without needing the selector itself.
--}}
<label for="{{ $id }}" class="flex cursor-pointer items-center gap-2 text-sm text-fg-ink">
    <span class="relative inline-flex shrink-0">
        <input
            id="{{ $id }}"
            type="checkbox"
            name="{{ $name }}"
            value="{{ $value }}"
            @if($checked) checked @endif
            class="peer size-4 touch:size-6 shrink-0 appearance-none rounded border border-input bg-fg-surface shadow-fg-1 transition-[background-color,border-color,box-shadow] duration-(--t-fast) ease-(--ease-standard) checked:border-accent checked:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
            {{ $attributes->except(['id','name','value']) }}
        />

        <span
            class="pointer-events-none absolute inset-0 grid scale-[0.8] place-items-center text-on-accent opacity-0 transition-[opacity,scale,transform] duration-(--t-fast) ease-(--ease-emphasis) peer-checked:scale-100 peer-checked:opacity-100"
            aria-hidden="true"
        >
            <x-ui.icon name="check" size="3" class="touch:size-4" />
        </span>
    </span>

    @if($label)
        <span>{{ $label }}</span>
    @endif

    {{ $slot }}
</label>
