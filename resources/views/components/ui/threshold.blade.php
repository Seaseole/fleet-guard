{{--
    @deprecated — moved to x-patterns.threshold. Shim only; delete when the last `<x-ui.threshold>` is gone.
--}}
@props(['label', 'valueText', 'value' => null, 'limit' => null, 'limitText' => null, 'caption' => null, 'tone' => 'neutral', 'href' => null, 'gated' => false, 'gate' => null, 'settleDelay' => null])

<x-patterns.threshold
    :label="$label"
    :value-text="$valueText"
    :value="$value"
    :limit="$limit"
    :limit-text="$limitText"
    :caption="$caption"
    :tone="$tone"
    :href="$href"
    :gated="$gated"
    :gate="$gate"
    :settle-delay="$settleDelay"
    {{ $attributes }}
/>
