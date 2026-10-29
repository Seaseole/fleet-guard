{{-- Single toast for one-off session flashes (used by the auth screens). --}}
@props(['tone' => 'info'])

<x-ui.alert :tone="$tone" {{ $attributes }}>
    {{ $slot }}
</x-ui.alert>
