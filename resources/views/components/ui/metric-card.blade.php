@props([
    'label',
    'figure' => null,
    'caption' => null,
    'zeroCaption' => null,
    'href' => null,
    'tone' => 'neutral',
    'gated' => false,
    'gate' => null,
])

@php
    // The tone colours the figure; the caption carries the words. Colour alone never says what is
    // wrong (DESIGN-SYSTEM.md §1.5), which is why a card with a tone always has a caption.
    $tones = [
        'neutral' => 'text-fg-ink',
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];

    $isZero = (int) $figure === 0;
    $link = ! $gated && $href !== null;

    // Display role (DESIGN-SYSTEM.md §3) — a dashboard hero figure is 30/34 semibold, and tabular
    // so four cards side by side align on the digit rather than the glyph width.
    $figureClasses = 'text-3xl leading-9 font-semibold tracking-[-0.015em] tabular-nums '.($tones[$tone] ?? $tones['neutral']);
@endphp

@if ($gated)
    <div {{ $attributes->class('grid gap-2 rounded-fg-md border border-dashed border-fg-line bg-fg-surface p-5') }}>
        <p class="text-xs font-medium tracking-[0.02em] text-fg-ink-muted">{{ $label }}</p>

        <p class="flex items-center gap-2 text-sm text-fg-ink-muted">
            <x-ui.icon name="lock-closed" size="4" />
            {{ __('Not available to your role') }}
        </p>

        <p class="text-xs leading-4 text-fg-ink-muted">
            {{ $gate === null ? __('Ask the Owner for the screen this figure comes from.') : __('Requires :ability.', ['ability' => $gate]) }}
        </p>
    </div>
@else
    <{{ $link ? 'a' : 'div' }}
        @if($link) href="{{ $href }}" wire:navigate @endif
        {{ $attributes->class([
            'group grid gap-2 rounded-fg-md border border-fg-line bg-fg-surface p-5 text-start shadow-fg-1 transition duration-(--t-fast) ease-(--ease-standard)',
            'hover:border-accent/45 hover:shadow-fg-2' => $link,
        ]) }}
    >
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium tracking-[0.02em] text-fg-ink-secondary">{{ $label }}</p>

            @if($link)
                <x-ui.icon
                    name="chevron-right"
                    size="4"
                    class="text-fg-ink-muted opacity-40 transition duration-(--t-fast) ease-(--ease-standard) group-hover:translate-x-0.5 group-hover:opacity-100 group-focus-visible:translate-x-0.5 group-focus-visible:opacity-100"
                />
            @endif
        </div>

        <p class="{{ $figureClasses }}">{{ $figure }}</p>

        <p class="text-xs leading-4 text-fg-ink-muted">
            {{ $isZero && $zeroCaption !== null ? $zeroCaption : $caption }}
        </p>
    </{{ $link ? 'a' : 'div' }}>
@endif
