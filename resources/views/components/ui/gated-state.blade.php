{{--
    The no-permission state, once (docs/DESIGN-SYSTEM.md 5: a closed seat is told what it is not
    shown, and which ability would open it). figure, threshold, metric-card and chart each carried
    a verbatim copy of this block; they now include this instead, so the sentence cannot drift.
--}}
@props(['label', 'gate' => null, 'padding' => 'p-5'])

<div {{ $attributes->class(['grid gap-2 rounded-fg-md border border-dashed border-fg-line bg-fg-surface', $padding]) }}>
    <p class="text-xs font-medium tracking-label text-fg-ink-muted">{{ $label }}</p>

    <p class="flex items-center gap-2 text-sm text-fg-ink-muted">
        <x-ui.icon name="lock-closed" size="4" />
        {{ __('Not available to your role') }}
    </p>

    <p class="text-xs leading-4 text-fg-ink-muted">
        {{ $gate === null ? __('Ask the Owner for the screen this figure comes from.') : __('Requires :ability.', ['ability' => $gate]) }}
    </p>
</div>
