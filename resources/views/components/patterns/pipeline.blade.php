@props([
    'steps' => [],
    'label' => null,
])

@php
    // Work order made visible. The five stages are the trip's own machine read as a queue:
    // what is still an enquiry, what is priced and waiting, what is planned but not committed, what is
    // on the road, and what has arrived without its proof back. Four equal tiles in a grid said the
    // same five numbers in an order a reader had to invent; this is the order already chose and
    // the reason each stage counts what it does.
    //
    // `steps` is a list of: label, figure, word (the state word beside the figure), caption, tone,
    // href. Tone colours the figure only — `word` is what makes the state readable without colour
    //, and the caption says what the stage is waiting for.
    $tones = [
        'neutral' => 'text-fg-ink',
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];
@endphp

<ol
    {{ $attributes->class('grid gap-4 lg:grid-flow-col lg:auto-cols-fr') }}
    @if ($label !== null)aria-label="{{ $label }}"@endif
>
    @foreach ($steps as $index => $step)
        @php
            $href = $step['href'] ?? null;
            $tone = $tones[$step['tone'] ?? 'neutral'] ?? $tones['neutral'];

            // One clock, one stagger: the rules draw left-to-right in stage order, so the picture of
            // "work moves this way" is the first thing that happens rather than a decoration after it.
            $delay = '--fg-settle-delay: '.($index * 40).'ms;';
        @endphp

        <li class="fg-settle grid gap-2" style="{{ $delay }}">
            <div class="fg-draw h-px w-full bg-fg-line-strong" style="--fg-draw-delay: {{ ($index * 40) + 60 }}ms;" aria-hidden="true"></div>

            @if ($href !== null)
                <a href="{{ $href }}" wire:navigate class="group grid gap-1 rounded-fg-md p-2 text-start -m-2 transition duration-(--t-fast) ease-(--ease-standard) hover:bg-fg-surface-hover focus-visible:bg-fg-surface-hover">
            @else
                <div class="grid gap-1 p-2 -m-2">
            @endif

                <p class="text-xs font-medium tracking-label text-fg-ink-secondary">{{ $step['label'] }}</p>

                <p class="flex items-baseline gap-2">
                    <span class="font-mono text-xl leading-7 font-medium tabular-nums {{ $tone }}">{{ $step['figure'] }}</span>

                    @if (! empty($step['word']))
                        <span class="text-xs leading-4 text-fg-ink-muted">{{ $step['word'] }}</span>

                        @if ($href !== null)
                            <x-ui.icon
                                name="chevron-right"
                                size="4"
                                class="ml-auto shrink-0 text-fg-ink-muted opacity-30 transition duration-(--t-fast) ease-(--ease-standard) group-hover:translate-x-0.5 group-hover:opacity-100 group-focus-visible:translate-x-0.5 group-focus-visible:opacity-100"
                            />
                        @endif
                    @endif
                </p>

                @if (! empty($step['caption']))
                    <p class="text-xs leading-4 text-fg-ink-muted">{{ $step['caption'] }}</p>
                @endif

            @if ($href !== null)
                </a>
            @else
                </div>
            @endif
        </li>
    @endforeach
</ol>
