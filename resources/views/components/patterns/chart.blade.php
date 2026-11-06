@props([
    'series' => null,
    'label' => null,
    'gated' => false,
    'gate' => null,
    'href' => null,
    'hrefLabel' => null,
])

{{--
    A chart and the table that says the same thing, from one object.

    Three things this component exists to keep true:

    1. The table is not optional. A canvas is a picture; a screen reader, a printed
       page and a seat that simply prefers numbers all need the rows, and they are generated from the same
       payload the bars are drawn from, so the two cannot disagree.
    2. The figures are already strings. Nothing here formats — `Money` and `Formatter` did that in the door
, which is why the tooltip and the table cell carry identical text.
    3. The canvas is sized once, not per breakpoint (docs/UX-UI-PLAN.md the U6): a chart that grows on a
       laptop and shrinks on a phone is the same control-size inversion the responsive pass already fixed.

    The payload is a JSON script island rather than an inline `x-data`: Blade's `@json` escapes quotes and angle
    brackets, so a caption containing `</script>` stays data, and the `\"` sequence the dashboard's own HTML test
    forbids never appears.
--}}
@if ($gated)
    {{-- Masked, never missing (docs/DESIGN-SYSTEM.md a closed seat is told what it is not shown, and which ability would open it). --}}
    <x-ui.gated-state :label="$label" :gate="$gate" {{ $attributes }} />
@elseif ($series->labels === [])
    <x-ui.card as="section" :title="$series->title" :description="$series->windowLabel" {{ $attributes }}>
        <x-ui.empty-state
            :title="__('Nothing to plot yet')"
            :description="$series->note ?? __('No rows fall in this window.')"
            icon="chart-bar"
        />
    </x-ui.card>
@else
    <x-patterns.card
        as="section"
        :title="$series->title"
        :description="$series->windowLabel"
        {{-- Keyed by the window, so a new window replaces this node instead of morphing a stale canvas in place;
             app.js's morph hook then re-mounts it. `wire:ignore` would freeze the payload *and* the table. --}}
        wire:key="chart-{{ $series->id }}-{{ $series->windowKey }}"
        {{-- Forwarded, not swallowed: the band decides how wide a chart is (`xl:col-span-2` for the trend), and
             the card appends its own defaults to whatever class the caller asked for. --}}
        {{ $attributes }}
    >
        @if($href)
            <x-slot:header>
                <x-ui.link :href="$href" wire:navigate>{{ $hrefLabel ?? __('Open the register') }}</x-ui.link>
            </x-slot:header>
        @endif

        <div class="grid gap-4">
            @if ($series->type === 'proportion')
                {{-- Drawn server-side (docs/REDESIGN-CONCEPT.md §4): the shares are text, so this figure needs
                     no canvas, no payload island and no JS chunk. --}}
                <x-patterns.proportion-bar :series="$series" />
            @elseif ($series->type === 'variance')
                <x-patterns.variance-bars :series="$series" />
            @else
                <script type="application/json" id="fg-chart-data-{{ $series->id }}">@json($series)</script>

                <div class="relative h-64 w-full">
                    {{-- role=img plus an aria-label names the picture; the table below is the content, this is the shape. --}}
                    <canvas
                        data-fg-chart="{{ $series->id }}"
                        role="img"
                        tabindex="0"
                        aria-label="{{ $series->ariaLabel }}"
                        aria-describedby="fg-chart-table-{{ $series->id }}"
                        class="h-full w-full"
                    ></canvas>
                </div>
            @endif

            @if($series->note)
                <x-ui.text class="text-fg-ink-muted">{{ $series->note }}</x-ui.text>
            @endif

            <details id="fg-chart-table-{{ $series->id }}" class="grid gap-2">
                {{-- Never `open`: the picture is the default view and the rows are for whoever asks for them. --}}
                <summary class="cursor-pointer text-xs font-medium tracking-label text-fg-ink-muted underline-offset-4 hover:text-fg-ink hover:underline">
                    {{ __('Figures for :title', ['title' => $series->title]) }}
                </summary>

                <x-ui.table :caption="$series->table['caption']">
                    <x-slot name="head">
                        @foreach ($series->table['columns'] as $column)
                            <x-ui.table.th :numeric="$column['numeric']">{{ $column['label'] }}</x-ui.table.th>
                        @endforeach
                    </x-slot>

                    @foreach ($series->table['rows'] as $row)
                        <tr>
                            @foreach ($row as $index => $cell)
                                <x-ui.table.td :numeric="$series->table['columns'][$index]['numeric'] ?? false">{{ $cell }}</x-ui.table.td>
                            @endforeach
                        </tr>
                    @endforeach

                    @if($series->table['totals'] !== [])
                        <tr class="bg-fg-surface-sunk">
                            @foreach ($series->table['totals'] as $index => $cell)
                                <x-ui.table.td class="font-semibold" :numeric="$series->table['columns'][$index]['numeric'] ?? false">{{ $cell }}</x-ui.table.td>
                            @endforeach
                        </tr>
                    @endif
                </x-ui.table>
            </details>
        </div>
    </x-ui.card>
@endif
