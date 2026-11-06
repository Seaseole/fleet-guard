@props(['series'])

{{--
    A signed measure against its own zero — the divergent bar of docs/REDESIGN-CONCEPT.md §4. Direction
    carries the sign, so a colour-blind seat reads over- and under- consumption by position, not hue; the
    tone colours the *judgement* the row already carries (the door sends state colours), and the risk tones
    are striped as well as coloured, matching the canvas charts' hatch rule.

    Bars run from the printed centre rail; their widths are geometric shares of the largest absolute value
    in this set, and every figure is the door's pre-formatted string.
--}}
@php
    $dataset = $series->datasets[0];
    $values = $dataset['data'];
    $figures = $dataset['formatted'];
    $colours = is_array($dataset['colour']) ? $dataset['colour'] : [$dataset['colour']];
    $patterns = is_array($dataset['pattern'] ?? null) ? $dataset['pattern'] : [$dataset['pattern'] ?? 'solid'];
    $maxAbs = array_reduce($values, fn (int $carry, int $value): int => max($carry, abs($value)), 0) ?: 1;
@endphp

<div class="grid gap-1.5" role="img" aria-label="{{ $series->ariaLabel }}">
    @foreach ($series->labels as $index => $label)
        @php
            $value = (int) ($values[$index] ?? 0);
            $width = round(abs($value) / $maxAbs * 50, 2);
            $colour = $colours[$index % count($colours)];
            $stripe = ($patterns[$index % count($patterns)] ?? 'solid') === 'stripe';
        @endphp

        <div class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)_5rem] items-center gap-3">
            <p class="fg-identifier truncate text-xs leading-4 text-fg-ink-secondary" title="{{ $label }}">{{ $label }}</p>

            <div class="relative h-4 overflow-hidden rounded-fg-sm border border-fg-line bg-fg-surface-sunk">
                <span class="absolute inset-y-0 left-1/2 w-px bg-fg-line-strong" aria-hidden="true"></span>
                <span
                    class="absolute inset-y-0.5 rounded-fg-sm"
                    style="{{ $value >= 0 ? 'left: calc(50% + 0.5px);' : 'right: calc(50% + 0.5px);' }} width: {{ $width }}%; background-color: var({{ $colour }}){{ $stripe ? '; background-image: repeating-linear-gradient(135deg, transparent 0 3px, var(--color-fg-surface) 3px 6px)' : '' }}"
                ></span>
            </div>

            <p class="fg-numeric text-xs leading-4 font-medium" style="color: var({{ $colour }})">{{ $figures[$index] ?? '—' }}</p>
        </div>
    @endforeach
</div>
