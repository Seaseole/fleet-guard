{{-- Default application shell: sidebar layout. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    {{ $slot }}
</x-layouts::app.sidebar>
