@props([
    'label',
    'valueText',
    'value' => null,
    'limit' => null,
    'limitText' => null,
    'caption' => null,
    'tone' => 'neutral',
    'href' => null,
    'gated' => false,
    'gate' => null,
    'settleDelay' => null,
])

@php
    // An instrument: the figure *and* the threshold it is measured against, on one rail. This exists
    // because the cards it replaces said "12 papers expiring" while the number that makes 12 a
    // problem — `documents.expiry_warning_days`, the derived window — lived on the Settings
    // screen. A measurement with no limit in the frame is a number the reader has to grade alone.
    //
    // The caller passes both the numeric pair and the display strings, and the words naming the limit
    // say where the limit came from: the bar is a picture of the comparison, never the only carrier
    // of it, which is why it is aria-hidden and the text is not.
    $tones = [
        'neutral' => ['figure' => 'text-fg-ink', 'fill' => 'bg-fg-ink-secondary'],
        'success' => ['figure' => 'text-success', 'fill' => 'bg-success'],
        'warning' => ['figure' => 'text-warning', 'fill' => 'bg-warning'],
        'danger' => ['figure' => 'text-danger', 'fill' => 'bg-danger'],
    ];

    $palette = $tones[$tone] ?? $tones['neutral'];
    $link = ! $gated && $href !== null;

    // The window is the limit, so 100 % is the threshold rather than a made-up maximum. An unknown
    // figure or an unset limit draws no rail: an empty bar would claim "nothing so far".
    $measurable = $value !== null && $limit !== null && $limit > 0;
    $percent = $measurable ? min(100, (int) round(((float) $value / (float) $limit) * 100)) : null;

    $settleStyle = $settleDelay === null ? null : '--fg-settle-delay: '.(int) $settleDelay.'ms;';
@endphp

@if ($gated)
    <x-ui.gated-state :label="$label" :gate="$gate" padding="p-4" {{ $attributes }} />
@else
    <{{ $link ? 'a' : 'div' }}
        @if ($link) href="{{ $href }}" wire:navigate @endif
        {{ $attributes->class([
            'group grid gap-2 rounded-fg-md p-4 text-start transition duration-(--t-fast) ease-(--ease-standard)',
            'hover:bg-fg-surface-hover focus-visible:bg-fg-surface-hover' => $link,
        ]) }}
    >
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-xs font-medium tracking-label text-fg-ink-secondary">{{ $label }}</p>

            @if ($link)
                <x-ui.icon
                    name="chevron-right"
                    size="4"
                    class="shrink-0 text-fg-ink-muted opacity-30 transition duration-(--t-fast) ease-(--ease-standard) group-hover:translate-x-0.5 group-hover:opacity-100 group-focus-visible:translate-x-0.5 group-focus-visible:opacity-100"
                />
            @endif
        </div>

        <p class="fg-settle flex items-baseline gap-2" style="{{ $settleStyle }}">
            <span class="font-mono text-xl leading-7 font-medium tabular-nums {{ $palette['figure'] }}">{{ $valueText }}</span>

            @if ($limitText !== null)
                <span class="text-xs leading-4 text-fg-ink-muted">{{ $limitText }}</span>
            @endif
        </p>

        @if ($measurable)
            {{-- Decorative by design: the two numbers above are the reading, and this is where they
                 sit on the scale. `role="progressbar"` would invite a percentage a screen reader then
                 announces next to the same figure twice. --}}
            <div class="relative h-1 w-full overflow-hidden rounded-full bg-fg-surface-sunk" aria-hidden="true">
                {{-- Static on purpose. The design allows transform and opacity only, and a bar that draws by
                     animating its own width is a layout animation; scaling it instead warps the rounded
                     ends. The rail's own reveal is the settle on the row above it, which is opacity. --}}
                <div class="absolute inset-y-0 left-0 rounded-full {{ $palette['fill'] }}" style="width: {{ $percent }}%;"></div>

                {{-- The limit is the rail's own end, so the tick is what makes "4 of 30" legible as a
                     threshold rather than as a bar chart of nothing. --}}
                <div class="absolute inset-y-0 right-0 w-px bg-fg-line-strong" aria-hidden="true"></div>
            </div>
        @endif

        @if ($caption !== null)
            <p class="text-xs leading-4 text-fg-ink-muted">{{ $caption }}</p>
        @endif
    </{{ $link ? 'a' : 'div' }}>
@endif
