{{-- 'card' variant: identical shell, wider measure for forms with more fields. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ App\Domain\Kernel\Settings\Theme::htmlClass() }}" data-theme-default="{{ App\Domain\Kernel\Settings\Theme::defaultMode() }}">
    <head>
        @include('partials.head')
    </head>

    <body class="grid min-h-dvh place-items-center bg-fg-canvas px-4 py-10 text-fg-ink">
        <main id="main" class="w-full max-w-lg rounded-fg-lg border border-fg-line bg-fg-surface p-8 shadow-fg-2">
            <x-auth-header :title="$title ?? null" />

            <div class="mt-6">
                {{ $slot }}
            </div>
        </main>

        <x-ui.toast.group />

        @php $shellFlashes = collect(session()->pull("flash_toasts", []))->concat(session("status") ? [["message" => session("status"), "kind" => "success"]] : [])->values()->all(); @endphp
        <x-flash-toasts :flashes="$shellFlashes" />
    </body>
</html>
