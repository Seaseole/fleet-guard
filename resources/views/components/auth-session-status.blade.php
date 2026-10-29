@props(['status' => null])

@if($status)
    <x-ui.alert tone="success" {{ $attributes }}>
        {{ $status }}
    </x-ui.alert>
@endif
