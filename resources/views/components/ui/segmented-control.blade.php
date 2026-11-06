{{--
    docs/component-ui.md PHASE 2: URL-driven via Livewire #[Url] (?view=board|list|timeline),
    tablist semantics, arrow-key navigation.

    The page owns the state — `model` names the Livewire property, `#[Url]` keeps it shareable,
    and the control says nothing about what the view does. Roving tabindex means one tab stop for
    the whole control, and Left/Right/Home/End move the selection the way a real tab strip does.
--}}
@props([
    'options' => [],
    'value' => null,
    'model' => null,
    'label' => null,
])

<div
    role="tablist"
    @if($label) aria-label="{{ $label }}" @endif
    x-data="{
        arrows(e) {
            const tabs = [...e.currentTarget.querySelectorAll('[role=tab]')];
            const at = tabs.indexOf(document.activeElement);
            if (at < 0) return;
            let to = { ArrowRight: at + 1, ArrowUp: at + 1, ArrowLeft: at - 1, ArrowDown: at - 1, Home: 0, End: tabs.length - 1 }[e.key];
            if (to === undefined) return;
            e.preventDefault();
            to = (to + tabs.length) % tabs.length;
            tabs[to].focus();
            tabs[to].click();
        },
    }"
    x-on:keydown="arrows($event)"
    {{ $attributes->class('inline-flex items-center gap-0.5 rounded-fg-md border border-input bg-fg-surface-sunk p-0.5') }}
>
    @foreach ($options as $optionValue => $option)
        @php
            $optionLabel = is_array($option) ? $option['label'] : $option;
            $optionIcon = is_array($option) ? ($option['icon'] ?? null) : null;
            $selected = (string) $value === (string) $optionValue;
        @endphp

        <button
            type="button"
            role="tab"
            aria-selected="{{ $selected ? 'true' : 'false' }}"
            tabindex="{{ $selected ? 0 : -1 }}"
            @if($model) x-on:click="$wire.set(@js($model), @js((string) $optionValue))" @endif
            @class([
                'inline-flex items-center gap-1.5 rounded-fg-sm px-3 py-1.5 text-sm font-medium whitespace-nowrap cursor-pointer',
                'transition duration-(--t-fast) ease-(--ease-standard)',
                'bg-fg-surface text-fg-ink shadow-fg-1' => $selected,
                'text-fg-ink-muted hover:bg-fg-surface-hover hover:text-fg-ink' => ! $selected,
            ])
        >
            @if($optionIcon)
                <x-ui.icon :name="$optionIcon" size="3" />
            @endif

            {{ $optionLabel }}
        </button>
    @endforeach
</div>
