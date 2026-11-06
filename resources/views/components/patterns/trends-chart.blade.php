@props([
    'payload' => null,
    'href' => null,
    'hrefLabel' => null,
])

{{--
    The cash trends card, in this app's own stack: server-rendered SVG in the first frame, Alpine for
    the scrubber and the legend, anime.js for the entrance and the period morph, and the token
    palette. The picture is a diverging column chart — cash in rises from a printed zero rail, cash
    out falls below it, both measured against one symmetric magnitude. A receipt and a payment are
    not parts of a single total, so they get directions rather than a stack, and the taller side is
    the one that moved the bank. This is `x-patterns.variance-bars` turned vertical: the same centre
    rail, and the same rule that position carries the direction while colour only carries the series.

    What the card keeps: one column per bucket, the round axis top (1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6 or
    8 × 10ⁿ), the dashed marker that fades at both ends, the tooltip that counts its amounts in, the
    grid as a slider with one tab stop, and the legend items as toggles that take a series off the
    grid.

    What it chooses on purpose: cash in is the one accent and cash out is the rail's own
    `fg-line-strong`, so the two never compete and a seat that reads no hue still knows which side of
    the zero the money went; and the tables below are not optional — the same promise
    `x-patterns.chart` makes, one per period so the rows follow the control that re-plotted the bars.

    The geometry constants are the drawing, not styling; they live in CashTrendsPayload, and the JS
    re-lays out to the measured width on mount.
--}}
@if ($payload === null || $payload->periods === [])
    <x-patterns.card as="section" :title="$payload?->title ?? __('Cash in, cash out')" {{ $attributes }}>
        <x-ui.empty-state
            :title="__('Nothing to plot yet')"
            :description="__('No cash has moved through the ledger in this window.')"
            icon="chart-bar"
        />
    </x-patterns.card>
@else
    @php
        $layout = $payload->layout();
        $data = $payload->periods[0]['data'];
        $n = count($data);

        $currency = \App\Domain\Kernel\Money\Currency::of($payload->currency);
        $scale = 10 ** $currency->minorUnits();
        $prefix = ((string) ($currency->symbol ?: $currency->code)).' ';

        $compact = static function (float $minor) use ($prefix, $scale): string {
            $major = $minor / $scale;

            if ($major >= 1000) {
                $k = $major / 1000;

                return $prefix.rtrim(rtrim(number_format($k, 2, '.', ''), '0'), '.').'K';
            }

            return $prefix.number_format($major, 0, '.', ' ');
        };

        $totalMinor = array_sum(array_map(static fn (array $b): int => $b['in'] - $b['out'], $data));
        $selected = max(0, $n - 1);
        $selectedBucket = $data[$selected];
        $selectedColumn = $layout['columns'][$selected];
        $markerX = $selectedColumn['x'] + $layout['barWidth'] / 2;

        $plot = \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_HEIGHT;
        $plotWidth = \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_WIDTH;
        $tipWidth = \App\Domain\Reporting\Series\CashTrendsPayload::TIP_WIDTH;
        $tipHeight = \App\Domain\Reporting\Series\CashTrendsPayload::TIP_HEIGHT;

        // The tooltip reads the taller side of the selected bucket, and sits beside the marker except
        // where the plot runs out of room.
        $tipFlipped = $markerX > $plotWidth - $tipWidth - 20;
        $tipTop = (int) round(max(
            4,
            min(
                $selectedColumn['inHeight'] >= $selectedColumn['outHeight']
                    ? $layout['zeroY'] - $selectedColumn['inHeight'] - $tipHeight
                    : $layout['zeroY'] + $selectedColumn['outHeight'] + 8,
                $plot - $tipHeight - 4,
            ),
        ));

        $zeroOffset = static fn (int|float $tick): float => $layout['usable'] * ($tick / $layout['top']);
        $tickTop = static fn (float $top): int => (int) round(max(0, min($top, $plot - 14)));
    @endphp

    <section
        x-data="fgTrends"
        x-init="start($el)"
        wire:key="trends-{{ $payload->id }}-{{ $payload->windowKey }}"
        {{ $attributes->class('w-full') }}
        role="group"
        aria-label="{{ $payload->title }}"
    >
        <script type="application/json" id="fg-trends-data-{{ $payload->id }}">@json($payload)</script>

        {{-- The outer card: a sunk frame around a white panel, 2 px of it (mebius' ring, this app's tokens). --}}
        <div class="grid gap-0 rounded-fg-xl border border-fg-line bg-fg-surface-sunk p-0.5 shadow-fg-1">
            <div class="flex items-center justify-between gap-4 p-3">
                <p class="min-w-0 truncate text-xl leading-7 font-semibold tracking-tight text-fg-ink" data-trends-heading>{{ $payload->title }}</p>

                @if ($href !== null)
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        class="grid size-8 touch:size-11 shrink-0 place-items-center rounded-fg-md border border-fg-line bg-fg-surface text-fg-ink-muted shadow-fg-1 transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-hover hover:text-fg-ink"
                        aria-label="{{ $hrefLabel ?? __('Open the cash-flow statement') }}"
                    >
                        <span aria-hidden="true" class="text-sm leading-none tracking-widest">⋯</span>
                    </a>
                @endif
            </div>

            {{-- The inner panel. --}}
            <div
                data-trends-panel
                class="flex flex-col gap-6 overflow-hidden rounded-fg-lg border border-fg-line bg-fg-surface p-4 shadow-fg-1"
            >
                <div class="flex flex-wrap items-center gap-x-6 gap-y-4">
                    <div class="flex min-w-0 flex-1 flex-col items-start gap-1">
                        <p class="text-sm leading-5 text-fg-ink-muted" data-trends-total-label>{{ $payload->totalLabel }}</p>

                        <p class="fg-numeric whitespace-nowrap text-3xl leading-9 font-semibold tracking-figure text-fg-ink" data-trends-total>{{ \App\Domain\Reporting\Support\Cells::minor($totalMinor, $payload->currency) }}</p>
                    </div>

                    @if (count($payload->periods) > 1)
                        <div role="group" aria-label="{{ __('Period') }}"
                            class="inline-flex shrink-0 items-center gap-0.5 rounded-fg-md border border-input bg-fg-surface-sunk p-0.5"
                        >
                            @foreach ($payload->periods as $period)
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="period === @js($period['value']) ? 'true' : 'false'"
                                    aria-checked="{{ $loop->first ? 'true' : 'false' }}"
                                    x-on:click="setPeriod(@js($period['value']))"
                                    :class="period === @js($period['value']) ? 'bg-fg-surface text-fg-ink shadow-fg-1' : 'text-fg-ink-muted hover:bg-fg-surface-hover hover:text-fg-ink'"
                                    class="rounded-fg-sm px-3 py-1.5 text-sm font-medium whitespace-nowrap transition duration-(--t-fast) ease-(--ease-standard)"
                                >{{ $period['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div role="group" aria-label="{{ __('Series') }}" class="flex flex-wrap items-center gap-3 py-1">
                    <button
                        type="button"
                        :aria-pressed="! hidden.includes('in')"
                        aria-pressed="true"
                        x-on:click="toggleSeries('in', $el)"
                        x-on:pointerenter="emphasize('in')"
                        x-on:pointerleave="release()"
                        class="flex cursor-pointer items-center gap-1.5 rounded-fg-md px-1 transition-colors duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-hover"
                        :style="hidden.includes('in') ? { opacity: 0.4 } : {}"
                    >
                        <span class="size-2.5 rounded-fg-xs bg-accent ring-1 ring-accent" aria-hidden="true"></span>
                        <span class="text-xs font-medium text-fg-ink-secondary">{{ $payload->inflowLabel }}</span>
                    </button>

                    <button
                        type="button"
                        :aria-pressed="! hidden.includes('out')"
                        aria-pressed="true"
                        x-on:click="toggleSeries('out', $el)"
                        x-on:pointerenter="emphasize('out')"
                        x-on:pointerleave="release()"
                        class="flex cursor-pointer items-center gap-1.5 rounded-fg-md px-1 transition-colors duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-hover"
                        :style="hidden.includes('out') ? { opacity: 0.4 } : {}"
                    >
                        <span class="size-2.5 rounded-fg-xs bg-fg-line-strong ring-1 ring-fg-line-strong" aria-hidden="true"></span>
                        <span class="text-xs font-medium text-fg-ink-secondary">{{ $payload->outflowLabel }}</span>
                    </button>
                </div>

                <div class="flex items-start gap-3">
                    {{-- The y axis is one magnitude scale, printed on both sides of the zero rail: the
                         distance from the rail is the amount, whichever direction it went. --}}
                    <div class="relative w-12 shrink-0" aria-hidden="true" data-trends-axis style="height: {{ \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_HEIGHT }}px;">
                        @foreach ($layout['ticks'] as $tick)
                            @php $offset = $zeroOffset($tick); @endphp

                            <span class="absolute left-0 whitespace-nowrap text-xs leading-4 text-fg-ink-muted" data-trends-tick style="top: {{ $tickTop($layout['zeroY'] - $offset - 7) }}px;">{{ $compact($tick) }}</span>

                            @if ($tick > 0)
                                <span class="absolute left-0 whitespace-nowrap text-xs leading-4 text-fg-ink-muted" data-trends-tick style="top: {{ $tickTop($layout['zeroY'] + $offset - 7) }}px;">{{ $compact($tick) }}</span>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-4">
                        <div data-trends-plot class="relative" style="height: {{ \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_HEIGHT }}px;">
                            <svg aria-hidden="true" class="pointer-events-none absolute inset-0 size-full overflow-visible" data-trends-svg>
                                <defs>
                                    {{-- The marker line fades to 2 % at both ends, as in the original. --}}
                                    <linearGradient id="fg-trends-marker-{{ $payload->id }}" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="0" y2="{{ \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_HEIGHT }}">
                                        <stop offset="0" style="stop-color: var(--color-fg-ink); stop-opacity: 0.02;" />
                                        <stop offset="0.5" style="stop-color: var(--color-fg-ink); stop-opacity: 1;" />
                                        <stop offset="1" style="stop-color: var(--color-fg-ink); stop-opacity: 0.02;" />
                                    </linearGradient>
                                </defs>

                                <g data-trends-columns>
                                    @foreach ($layout['columns'] as $j => $column)
                                        @php $radius = round(min(2, $layout['barWidth'] / 2), 2); @endphp

                                        <g data-trends-column data-col="{{ $j }}">
                                            @if ($column['in'] > 0)
                                                <rect
                                                    data-trends-bar
                                                    data-series="in"
                                                    data-col="{{ $j }}"
                                                    x="{{ round($column['x'], 2) }}"
                                                    y="{{ round($layout['zeroY'] - $column['inHeight'], 2) }}"
                                                    width="{{ round($layout['barWidth'], 2) }}"
                                                    height="{{ $column['inHeight'] }}"
                                                    rx="{{ $radius }}"
                                                    class="fill-accent transition-[fill] duration-(--t-base) ease-(--ease-standard)"
                                                />
                                            @endif

                                            @if ($column['out'] > 0)
                                                <rect
                                                    data-trends-bar
                                                    data-series="out"
                                                    data-col="{{ $j }}"
                                                    x="{{ round($column['x'], 2) }}"
                                                    y="{{ round($layout['zeroY'], 2) }}"
                                                    width="{{ round($layout['barWidth'], 2) }}"
                                                    height="{{ $column['outHeight'] }}"
                                                    rx="{{ $radius }}"
                                                    class="fill-fg-line-strong transition-[fill] duration-(--t-base) ease-(--ease-standard)"
                                                />
                                            @endif
                                        </g>
                                    @endforeach
                                </g>

                                {{-- The rail both sides are measured from, printed so the zero is a fact on
                                     the picture rather than something a reader infers from the gutter. --}}
                                <line data-trends-zero x1="0" y1="{{ round($layout['zeroY'], 2) }}" x2="100%" y2="{{ round($layout['zeroY'], 2) }}" class="stroke-fg-ink-muted" stroke-width="1" />

                                <g transform="translate({{ round($markerX, 2) }} 0)" data-trends-marker-wrap>
                                    <line data-trends-marker x1="0" x2="0" y1="0" y2="{{ \App\Domain\Reporting\Series\CashTrendsPayload::PLOT_HEIGHT }}" stroke="url(#fg-trends-marker-{{ $payload->id }})" stroke-width="1" stroke-dasharray="4 4" />
                                </g>
                            </svg>

                            {{-- The tooltip: the selected bucket's dates and both amounts. --}}
                            <div
                                data-trends-tooltip
                                aria-hidden="true"
                                class="pointer-events-none absolute z-(--z-menu) flex flex-col gap-2 overflow-hidden rounded-fg-lg bg-fg-surface p-1 whitespace-nowrap shadow-fg-2"
                                style="{{ $tipFlipped ? 'right: '.round(\App\Domain\Reporting\Series\CashTrendsPayload::PLOT_WIDTH - $markerX + 10).'px;' : 'left: '.round($markerX + 10).'px;' }} top: {{ $tipTop }}px;"
                            >
                                <p class="rounded-fg-sm bg-fg-surface-sunk px-2 py-1 text-sm leading-5 text-fg-ink">
                                    {{ \Carbon\CarbonImmutable::parse($selectedBucket['date'])->translatedFormat('j M Y') }}
                                </p>

                                <div class="grid gap-1 px-1 pb-1">
                                    <div class="flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-fg-xs bg-accent ring-1 ring-accent" aria-hidden="true"></span>
                                        <span class="min-w-0 truncate text-sm text-fg-ink-muted">{{ $payload->inflowLabel }}</span>
                                        <span class="fg-numeric ms-auto text-sm font-medium text-fg-ink" data-trends-tip-value data-key="in" data-value="{{ $selectedBucket['in'] }}">{{ \App\Domain\Reporting\Support\Cells::minor($selectedBucket['in'], $payload->currency) }}</span>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-fg-xs bg-fg-line-strong ring-1 ring-fg-line-strong" aria-hidden="true"></span>
                                        <span class="min-w-0 truncate text-sm text-fg-ink-muted">{{ $payload->outflowLabel }}</span>
                                        <span class="fg-numeric ms-auto text-sm font-medium text-fg-ink" data-trends-tip-value data-key="out" data-value="{{ $selectedBucket['out'] }}">{{ \App\Domain\Reporting\Support\Cells::minor($selectedBucket['out'], $payload->currency) }}</span>
                                    </div>
                                </div>
                            </div>

                            {{-- The grid is a slider: one tab stop, arrows move the column. --}}
                            <div
                                role="slider"
                                tabindex="0"
                                aria-orientation="horizontal"
                                aria-label="{{ $payload->ariaLabel }}"
                                :aria-valuetext="describe()"
                                aria-valuemin="1"
                                aria-valuemax="{{ max(1, $n) }}"
                                aria-valuenow="{{ $selected + 1 }}"
                                x-on:pointermove="preview($event)"
                                x-on:pointerleave="preview(null)"
                                x-on:pointerup="$event.button === 0 && pick($event)"
                                x-on:keydown="onKey($event)"
                                class="absolute -inset-1 cursor-pointer touch-pan-y rounded-fg-md"
                            ></div>
                        </div>

                        {{-- The x labels, each centred on its bucket. --}}
                        <div class="relative h-4" aria-hidden="true" data-trends-xlabels>
                            @foreach ($layout['labels'] as $label)
                                <span class="absolute top-0" style="left: {{ round($label['x'], 2) }}px;">
                                    <span class="block -translate-x-1/2 whitespace-nowrap text-xs leading-4 text-fg-ink-muted">{{ $label['text'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- The rows that say what the columns say. Never open: the picture is the default view.
                     The server renders the default period; Alpine rewrites these rows in place when the
                     Week / Month / Year control re-plots the bars, so the table never contradicts the
                     picture and a seat with no JS still reads the figures it was drawn with. --}}
                <details class="grid gap-2">
                    <summary class="cursor-pointer text-xs font-medium tracking-label text-fg-ink-muted underline-offset-4 hover:text-fg-ink hover:underline">
                        {{ __('Figures for :title', ['title' => $payload->title]) }}
                    </summary>

                    @php $defaultTable = $payload->tables[$payload->periods[0]['value']]; @endphp

                    <x-ui.table :caption="$defaultTable['caption']" data-trends-table>
                        <x-slot name="head">
                            @foreach ($defaultTable['columns'] as $column)
                                <x-ui.table.th :numeric="$column['numeric']">{{ $column['label'] }}</x-ui.table.th>
                            @endforeach
                        </x-slot>

                        @foreach ($defaultTable['rows'] as $row)
                            <tr>
                                @foreach ($row as $index => $cell)
                                    <x-ui.table.td :numeric="$defaultTable['columns'][$index]['numeric'] ?? false">{{ $cell }}</x-ui.table.td>
                                @endforeach
                            </tr>
                        @endforeach

                        @if ($defaultTable['totals'] !== [])
                            <tr class="bg-fg-surface-sunk" data-trends-totals>
                                @foreach ($defaultTable['totals'] as $index => $cell)
                                    <x-ui.table.td class="font-semibold" :numeric="$defaultTable['columns'][$index]['numeric'] ?? false">{{ $cell }}</x-ui.table.td>
                                @endforeach
                            </tr>
                        @endif
                    </x-ui.table>
                </details>
            </div>
        </div>
    </section>
@endif
