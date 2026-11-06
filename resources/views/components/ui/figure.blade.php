{{--
    @deprecated — moved to x-patterns.figure. Shim only; delete when the last `<x-ui.figure>` is gone.
--}}
@props(['label', 'value', 'caption' => null, 'note' => null, 'tone' => 'neutral', 'href' => null, 'gated' => false, 'gate' => null, 'settleDelay' => null])

<x-patterns.figure
    :label="$label"
    :value="$value"
    :caption="$caption"
    :note="$note"
    :tone="$tone"
    :href="$href"
    :gated="$gated"
    :gate="$gate"
    :settle-delay="$settleDelay"
    {{ $attributes }}
/>
