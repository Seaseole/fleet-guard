@props(['heading' => null, 'label' => null])

{{-- `heading` is a visible section title and so doubles as the accessible name; `label` names the
     region for a screen reader when the visible title lives in the card around this list. --}}
<nav aria-label="{{ $label ?? $heading ?? __('Navigation') }}" {{ $attributes->class('grid gap-0.5') }}>
    @if($heading)
        <p class="px-2 pb-1 pt-3 text-xs font-medium text-fg-ink-muted">{{ $heading }}</p>
    @endif

    {{ $slot }}
</nav>
