{{--
    @deprecated — moved to x-patterns.pipeline. Shim only; delete when the last `<x-ui.pipeline>` is gone.
--}}
@props(['steps' => [], 'label' => null])

<x-patterns.pipeline :steps="$steps" :label="$label" {{ $attributes }} />
