{{-- 'card' variant: identical shell, wider measure for forms with more fields. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ App\Domain\Kernel\Settings\Theme::htmlClass() }}" data-theme-default="{{ App\Domain\Kernel\Settings\Theme::defaultMode() }}">
    <head>
        @include('partials.head')
    </head>

    <body class="grid min-h-dvh place-items-center bg-fg-canvas px-4 py-10 text-fg-ink">
        <div class="w-full max-w-lg rounded-fg-lg border border-fg-line bg-fg-surface p-8 shadow-fg-2">
            <x-auth-header :title="$title ?? null" />

            <div class="mt-6">
                {{ $slot }}
            </div>
        </div>

        <x-ui.toast.group />
        <x-flash-toasts />
    </body>
</html>
