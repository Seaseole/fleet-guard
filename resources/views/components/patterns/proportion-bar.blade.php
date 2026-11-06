@props(['series'])

{{--
    A part-to-whole read as one stacked bar (docs/REDESIGN-CONCEPT.md §4). It replaces the doughnut because
    slices graded C on accessibility — distinguishable by hue alone — while a bar can carry each share as
    text, keep the risk segments striped as well as coloured, and leave a table underneath for anyone who
    wants the exact figures (rendered by the chart shell, from the same Series object).

    Widths are geometry, not truth: they come from the door's own counts, while every number printed here
    is the door's pre-formatted string, so the bar cannot disagree with the table below it.
--}}
@php
    $dataset = $series->datasets[0];
    $counts = $dataset['data'];
    $shares = $dataset['formatted'];
    // Truck counts print raw; money sides (the balance-sheet composition) send their own formatted
    // strings so the legend never prints a minor-unit integer next to a formatted table.
    $amounts = $dataset['amounts'] ?? null;
    $colours = is_array($dataset['colour']) ? $dataset['colour'] : [$dataset['colour']];
    $patterns = is_array($dataset['pattern'] ?? null) ? $dataset['pattern'] : [$dataset['pattern'] ?? 'solid'];
    $total = array_sum($counts);
    $value = fn (int $index): string => $amounts !== null ? (string) $amounts[$index] : (string) $counts[$index];
    $style = fn (int $index): string => 'background-color: var('.$colours[$index % count($colours)].')'.
        (($patterns[$index % count($patterns)] ?? 'solid') === 'stripe'
            ? '; background-image: repeating-linear-gradient(135deg, transparent 0 4px, var(--color-fg-surface) 4px 8px)'
            : '');
@endphp

@if ($total <= 0)
    <p class="text-xs leading-4 text-fg-ink-muted">{{ __('Nothing to plot — every segment is zero.') }}</p>
@else
    <div class="grid gap-3">
        <div role="img" aria-label="{{ $series->ariaLabel }}" class="flex h-9 w-full overflow-hidden rounded-fg-sm border border-fg-line">
            @foreach ($series->labels as $index => $label)
                @php($count = (int) ($counts[$index] ?? 0))

                @if ($count > 0)
                    @php($width = round($count / $total * 100, 2))

                    <div
                        class="flex h-full items-center justify-center overflow-hidden {{ $index > 0 ? 'border-l-2 border-fg-surface' : '' }}"
                        style="width: {{ $width }}%; {{ $style($index) }}"
                        title="{{ $label }} — {{ $value($index) }} ({{ $shares[$index] }})"
                    >
                        @if ($width >= 12)
                            <span class="rounded-fg-sm bg-fg-surface px-1.5 py-0.5 text-2xs font-medium tracking-label whitespace-nowrap text-fg-ink">{{ $shares[$index] }}</span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        {{-- The legend repeats the bar in words: label, count, share — so no reader needs the hue to tell the
             segments apart, and the narrow slices that kept their number out of the bar still say it. --}}
        <ul class="flex flex-wrap gap-x-5 gap-y-1.5">
            @foreach ($series->labels as $index => $label)
                @if ((int) ($counts[$index] ?? 0) > 0)
                    <li class="flex items-center gap-2 text-xs leading-4">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-fg-sm border border-fg-line" style="{{ $style($index) }}" aria-hidden="true"></span>
                        <span class="text-fg-ink-secondary">{{ $label }}</span>
                        <span class="fg-identifier text-fg-ink">{{ $value($index) }}</span>
                        <span class="text-fg-ink-muted">{{ $shares[$index] }}</span>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
