{{--
    The row between the page header and the data view: view switcher, search, filters. It owns
    nothing — the page passes `x-ui.segmented-control`, `x-ui.input` and friends as the slot — and
    only guarantees the wrap order and the 8 px gap the design reference documents.
--}}

<div role="toolbar" aria-label="{{ __('View controls') }}" {{ $attributes->class('flex flex-wrap items-center gap-2') }}>
    {{ $slot }}
</div>
