@php
    // Server-side flashes become client toasts once, on render, so a redirected
    // request still acknowledges the action (DESIGN-SYSTEM.md §1.4).
    $flashes = collect(session()->get('flash_toasts', []))
        ->concat(session('status') ? [['message' => session('status'), 'kind' => 'success']] : [])
        ->values();

    session()->forget('flash_toasts');
@endphp

@if($flashes->isNotEmpty())
    <script>
        document.addEventListener('alpine:init', () => {
            @foreach($flashes as $flash)
                window.Alpine?.store?.('fgToasts')?.push(@js($flash));
            @endforeach
        });
    </script>
@endif
