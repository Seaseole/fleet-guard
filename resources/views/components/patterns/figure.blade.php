@props([
    'label',
    'value',
    'caption' => null,
    'note' => null,
    'tone' => 'neutral',
    'href' => null,
    'gated' => false,
    'gate' => null,
    'settleDelay' => null,
])

@php
    // The one Display figure of a view (DESIGN-SYSTEM.md 30/34 semibold, -0.015em). A page gets
    // exactly one of these — DESIGN-SYSTEM.md the "one accent, or none is" applied to volume rather than colour:
    // twenty-six figures at 30 px is the same as none of them.
    //
    // The tone colours the figure and the note carries the words, because DESIGN-SYSTEM.md the design forbids colour as the
    // only carrier of state.
    $tones = [
        'neutral' => 'text-fg-ink',
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];

    $link = ! $gated && $href !== null;

    // Tailwind v4 cannot see a class name built by interpolation (the defect 4), so the stagger is
    // a custom property on the element and `.fg-settle` reads it. Delay in ms, never a width query.
    $settleStyle = $settleDelay === null ? null : '--fg-settle-delay: '.(int) $settleDelay.'ms;';
@endphp

@if ($gated)
    <x-ui.gated-state :label="$label" :gate="$gate" {{ $attributes }} />
@else
    <{{ $link ? 'a' : 'div' }}
        @if ($link) href="{{ $href }}" wire:navigate @endif
        {{ $attributes->class([
            'group grid content-start gap-2 text-start',
            'rounded-fg-md p-5 transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-hover focus-visible:bg-fg-surface-hover' => $link,
        ]) }}
    >
        <p class="text-xs font-medium tracking-label text-fg-ink-secondary">{{ $label }}</p>

        <p class="fg-settle text-3xl leading-9 font-semibold tracking-figure tabular-nums {{ $tones[$tone] ?? $tones['neutral'] }}" style="{{ $settleStyle }}">
            {{ $value }}

            @if ($link)
                <x-ui.icon
                    name="chevron-right"
                    size="5"
                    class="ml-1 inline-block align-middle text-fg-ink-muted opacity-30 transition duration-(--t-fast) ease-(--ease-standard) group-hover:translate-x-0.5 group-hover:opacity-100 group-focus-visible:translate-x-0.5 group-focus-visible:opacity-100"
                />
            @endif
        </p>

        @if ($caption !== null)
            <p class="fg-settle text-sm leading-5 text-fg-ink-secondary" style="{{ $settleStyle }}">{{ $caption }}</p>
        @endif

        @if ($note !== null)
            <p class="text-xs leading-4 text-fg-ink-muted">{{ $note }}</p>
        @endif
    </{{ $link ? 'a' : 'div' }}>
@endif
