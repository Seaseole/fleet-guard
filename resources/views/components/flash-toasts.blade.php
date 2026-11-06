@props(['flashes' => []])

{{--
    Presentational: the shell pulls the flashes out of the session and hands them over. Server-side
    flashes become client toasts once, on render, so a redirected request still acknowledges the
    action.
--}}
@if($flashes !== [])
    <script>
        document.addEventListener('alpine:init', () => {
            @foreach($flashes as $flash)
                window.Alpine?.store?.('fgToasts')?.push(@js($flash));
            @endforeach
        });
    </script>
@endif
