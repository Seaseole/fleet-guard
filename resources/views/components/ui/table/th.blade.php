@props(['numeric' => false, 'sortable' => false, 'direction' => null, 'hideBelow' => null])

<th
    scope="col"
    @if($sortable) aria-sort="{{ $direction === 'asc' ? 'ascending' : ($direction === 'desc' ? 'descending' : 'none') }}" @endif
    {{ $attributes->class([
        'whitespace-nowrap border-b border-fg-line px-3 py-2 text-start text-xs font-medium text-fg-ink-muted',
        'text-right' => $numeric,
        'hidden md:table-cell' => $hideBelow === 'md',
        'hidden lg:table-cell' => $hideBelow === 'lg',
        'hidden xl:table-cell' => $hideBelow === 'xl',
    ]) }}
>
    @if($sortable)
        {{--
            Bare `transition`, not an explicit property list: the chevron below turns with `rotate`, and
            Tailwind v4 compiles `rotate-*` to its own `rotate:` property rather than into `transform:`. A
            list that names `transform` would fade the colour and snap the flip.

            The chevron is one icon rotated instead of two icons swapped, so a seat that reverses a sort
            watches the same glyph turn over — which says "the other way" — rather than watching an unrelated
            shape replace it. Rotation is movement on screen, so it takes the standard curve: `--ease-enter`
            would make the turn leave too hard to read as something the control did deliberately.
        --}}
        <button
            type="button"
            {{ $attributes->only('wire:click')->class('group inline-flex items-center gap-1 transition duration-(--t-fast) ease-(--ease-standard) hover:text-fg-ink') }}
        >
            {{-- The label carries the underline, not the button: a sort control answers a pointer the way a
                 link does, and a rule drawn through the chevron would read as a struck-out glyph. --}}
            <span class="underline-offset-4 group-hover:underline">{{ $slot }}</span>
            <x-ui.icon
                name="chevron-down"
                size="3"
                class="{{ $direction === 'asc' ? '-rotate-180' : '' }} {{ $direction ? '' : 'opacity-40' }}"
            />
        </button>
    @else
        {{ $slot }}
    @endif
</th>
