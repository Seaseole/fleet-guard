@props(['class' => 'h-4 w-full'])

{{-- Geometry matches the real layout so nothing jumps on arrival (§5). --}}
<span role="status" aria-busy="true" aria-label="{{ __('Loading') }}" {{ $attributes->class(['fg-skeleton block rounded-fg-sm', $class]) }}></span>
