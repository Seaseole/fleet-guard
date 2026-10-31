{{--
    Header layout variant. The sidebar layout is the default for this app
    (12 modules do not fit in a top nav), but the kit's switch stays honest:
    choose this file from layouts/app.blade.php and nothing else changes.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ App\Domain\Kernel\Settings\Theme::htmlClass() }}" data-theme-default="{{ App\Domain\Kernel\Settings\Theme::defaultMode() }}">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-dvh bg-fg-canvas text-fg-ink">
        <header class="sticky top-0 z-30 border-b border-fg-line bg-fg-surface/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-[1400px] items-center gap-3 px-4">
                <x-app-logo :href="route('dashboard')" wire:navigate />

                <nav class="hidden flex-1 items-center gap-1 overflow-x-auto lg:flex" aria-label="{{ __('Main navigation') }}">
                    @include('partials.navigation', ['collapsed' => false, 'inline' => true])
                </nav>

                <div class="ms-auto flex items-center gap-1">
                    <x-quick-actions />
                    <x-notifications-button />
                    <x-theme-toggle />
                    <x-desktop-user-menu :collapsed="false" position="bottom" />
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-[1400px] p-4 lg:p-6">
            {{ $slot }}
        </main>

        <x-ui.toast.group />
        <x-flash-toasts />
    </body>
</html>
