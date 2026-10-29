{{-- Default application shell: sidebar layout (DESIGN-SYSTEM.md §7). --}}
<x-layouts::app.sidebar :title="$title ?? null">
    {{ $slot }}
</x-layouts::app.sidebar>
