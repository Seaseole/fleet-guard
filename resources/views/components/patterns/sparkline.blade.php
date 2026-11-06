@props([
    'values' => [],
    'label' => null,
    'width' => 96,
    'height' => 24,
    'tone' => 'accent',
])

{{--
    A compact trend, drawn server-side as one polyline — docs/REDESIGN-CONCEPT.md §4. It is deliberately
    not a Chart.js canvas: a figure beside a row cannot pay for a rendering library, and the shape is
    decoration for a number that is already printed beside it. That is why the svg is aria-hidden unless
    the caller supplies a label: where the number sits next to it, the row's text is the content and the
    line is the shape; where the line would be alone, the label names what it says.

    Fixed pixel geometry (no responsive resize, no layout shift), viewBox-matched width/height, and a
    stroke driven by a token so the theme flip recolours it for free — same rule charts.js follows for
    the big figures.
--}}
@php
    $strokes = [
        'accent' => 'var(--color-accent)',
        'ink' => 'var(--color-fg-ink)',
        'success' => 'var(--color-success)',
        'warning' => 'var(--color-warning)',
        'danger' => 'var(--color-danger)',
        'neutral' => 'var(--color-fg-ink-muted)',
    ];
    $stroke = $strokes[$tone] ?? $strokes['accent'];
    $values = array_values(array_filter($values, is_numeric(...)));
@endphp

@if (count($values) >= 2)
    @php
        $min = min($values);
        $max = max($values);
        $span = $max - $min;
        $last = count($values) - 1;

        $points = [];
        foreach ($values as $index => $value) {
            $x = round($index * ($width - 4) / $last + 2, 1);
            // A flat series sits on the middle line; otherwise the newest point claims the right edge.
            $y = $span == 0 ? round($height / 2, 1) : round($height - 2 - (($value - $min) / $span) * ($height - 4), 1);
            $points[] = $x.','.$y;
        }
        [$tipX, $tipY] = explode(',', $points[$last]);
    @endphp

    <svg
        width="{{ $width }}"
        height="{{ $height }}"
        viewBox="0 0 {{ $width }} {{ $height }}"
        @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
        {{ $attributes->class('shrink-0 overflow-visible') }}
    >
        <polyline fill="none" stroke="{{ $stroke }}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="{{ implode(' ', $points) }}" />
        <circle cx="{{ $tipX }}" cy="{{ $tipY }}" r="2" fill="{{ $stroke }}" />
    </svg>
@endif
