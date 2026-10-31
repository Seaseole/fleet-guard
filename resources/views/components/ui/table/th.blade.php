@props(['numeric' => false, 'sortable' => false, 'direction' => null])

<th
    scope="col"
    @if($sortable) aria-sort="{{ $direction === 'asc' ? 'ascending' : ($direction === 'desc' ? 'descending' : 'none') }}" @endif
    {{ $attributes->class([
        'whitespace-nowrap border-b border-fg-line px-3 py-2 text-start text-xs font-medium text-fg-ink-muted',
        'text-right' => $numeric,
    ]) }}
>
    @if($sortable)
        <button type="button" {{ $attributes->only('wire:click')->class('inline-flex items-center gap-1 hover:text-fg-ink') }}>
            {{ $slot }}
            <x-ui.icon :name="$direction === 'asc' ? 'chevron-up' : 'chevron-down'" size="3" />
        </button>
    @else
        {{ $slot }}
    @endif
</th>
