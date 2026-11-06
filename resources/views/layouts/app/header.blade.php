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
@php
            /* The shell's composition reads (docs/component-ui.md rule 7): the topbar components are presentational, so the Gate matrix, the unread count, the session flashes and the site name are gathered here, not inside a component. */
            $shellUser = auth()->user();
            $shellSiteName = \App\Domain\Kernel\Tenancy\SiteName::current();
            $shellAlertsHref = \Illuminate\Support\Facades\Route::has('alerts.index') ? route('alerts.index') : null;
            $shellUnread = $shellUser?->unread_alerts_count ?? 0;
            $shellCreateActions = collect([
                ['label' => __('Register document'), 'route' => 'documents.register', 'permission' => 'documents.upload', 'icon' => 'folder'],
                ['label' => __('New journal'), 'route' => 'accounting.journal.create', 'permission' => 'ledger.journal.manual', 'icon' => 'calculator'],
                ['label' => __('New job'), 'route' => 'jobs.create', 'permission' => 'jobs.create', 'icon' => 'clipboard-document-list'],
                ['label' => __('Dispatch trip'), 'route' => 'trips.create', 'permission' => 'trips.create', 'icon' => 'map'],
                ['label' => __('Record fuel'), 'route' => 'fuel.index', 'permission' => 'fuel.record', 'icon' => 'circle-stack'],
                ['label' => __('New work order'), 'route' => 'maintenance.index', 'permission' => 'work_orders.create', 'icon' => 'wrench'],
                ['label' => __('New invoice'), 'route' => 'invoices.create', 'permission' => 'invoices.create', 'icon' => 'receipt-percent'],
            ])->filter(fn (array $a) => \Illuminate\Support\Facades\Route::has($a['route']) && \Illuminate\Support\Facades\Gate::allows($a['permission']))->map(fn (array $a): array => ['label' => $a['label'], 'href' => route($a['route']), 'icon' => $a['icon']])->values()->all();
            $shellFlashes = collect(session()->get('flash_toasts', []))->concat(session('status') ? [['message' => session('status'), 'kind' => 'success']] : [])->values()->all();
            session()->forget('flash_toasts');
        @endphp

        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-(--z-toast) focus:rounded-fg-md focus:bg-accent focus:px-3 focus:py-2 focus:text-sm focus:text-on-accent"
        >{{ __('Skip to content') }}</a>

        <header class="sticky top-0 z-(--z-sticky) border-b border-fg-line bg-fg-surface/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-[1400px] items-center gap-3 px-4">
                <x-app-logo :name="$shellSiteName" :href="route('dashboard')" wire:navigate />

                <nav class="hidden flex-1 items-center gap-1 overflow-x-auto lg:flex" aria-label="{{ __('Main navigation') }}">
                    @include('partials.navigation', ['collapsed' => false, 'inline' => true])
                </nav>

                <div class="ms-auto flex items-center gap-1">
                    <x-quick-actions :actions="$shellCreateActions" />
                    <x-notifications-button :href="$shellAlertsHref" :count="$shellUnread" />
                    <x-theme-toggle />
                    <x-desktop-user-menu :name="$shellUser?->name" :email="$shellUser?->email" :initials="$shellUser?->initials()" :settings-href="route('profile.edit')" :logout-href="route('logout')" :collapsed="false" position="bottom" />
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-[1400px] p-4 lg:p-6">
            {{ $slot }}
        </main>

        <x-ui.toast.group />
        <x-flash-toasts :flashes="$shellFlashes" />
    </body>
</html>
