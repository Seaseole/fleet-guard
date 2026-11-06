{{--
    @deprecated — moved to x-patterns.chart. Shim only; delete when the last `<x-ui.chart>` is gone.
--}}
@props(['series' => null, 'label' => null, 'gated' => false, 'gate' => null, 'href' => null, 'hrefLabel' => null])

<x-patterns.chart
    :series="$series"
    :label="$label"
    :gated="$gated"
    :gate="$gate"
    :href="$href"
    :href-label="$hrefLabel"
    {{ $attributes }}
>
    {{ $slot }}
</x-patterns.chart>
