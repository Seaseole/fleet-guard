{{--
    @deprecated — moved to x-patterns.metric-card. Shim only; delete when the last
    `<x-ui.metric-card>` is gone.
--}}
@props(['label', 'figure' => null, 'caption' => null, 'zeroCaption' => null, 'href' => null, 'tone' => 'neutral', 'gated' => false, 'gate' => null])

<x-patterns.metric-card
    :label="$label"
    :figure="$figure"
    :caption="$caption"
    :zero-caption="$zeroCaption"
    :href="$href"
    :tone="$tone"
    :gated="$gated"
    :gate="$gate"
    {{ $attributes }}
/>
