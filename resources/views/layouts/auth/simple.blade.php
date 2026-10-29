<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-dvh bg-fg-canvas text-fg-ink">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-8 px-4 py-10">
            <div class="flex justify-center">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-fg-ink">
                    <span class="grid size-9 place-items-center rounded-fg-md bg-accent text-on-accent">
                        <x-ui.icon name="truck" size="5" />
                    </span>

                    <span class="text-base font-semibold">{{ config('app.name', 'Fleet Guard') }}</span>
                </a>
            </div>

            <div class="rounded-fg-lg border border-fg-line bg-fg-surface p-6 shadow-fg-1">
                {{ $slot }}
            </div>

            <div class="flex items-center justify-center gap-2">
                <x-theme-toggle class="size-8" />
            </div>
        </div>

        <x-ui.toast.group />
        <x-flash-toasts />
    </body>
</html>
