<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ App\Domain\Kernel\Settings\Theme::htmlClass() }}" data-theme-default="{{ App\Domain\Kernel\Settings\Theme::defaultMode() }}">
    <head>
        @include('partials.head')
    </head>

    <body
        class="min-h-dvh bg-fg-canvas text-fg-ink"
        x-data="{
            mobileOpen: false,
            menuTrigger: null,
            collapsed: localStorage.getItem('fg_sidebar_collapsed') === '1',
            toggleCollapsed() {
                this.collapsed = !this.collapsed;
                localStorage.setItem('fg_sidebar_collapsed', this.collapsed ? '1' : '0');
            },
            openMenu(trigger) {
                this.menuTrigger = trigger;
                this.mobileOpen = true;
            },
            closeMenu() {
                this.mobileOpen = false;
                this.menuTrigger?.focus();
            },
            trapMobileTab(event) {
                if (! this.mobileOpen || event.key !== 'Tab') return;

                const panel = document.querySelector('[data-mobile-nav]');
                const items = [...panel.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]')]
                    .filter(item => item.offsetParent !== null && item.getAttribute('tabindex') !== '-1');

                if (items.length === 0) return;

                const first = items[0];
                const last = items.at(-1);

                if (event.shiftKey && (document.activeElement === first || ! panel.contains(document.activeElement))) {
                    event.preventDefault();
                    last.focus();
                } else if (! event.shiftKey && (document.activeElement === last || ! panel.contains(document.activeElement))) {
                    event.preventDefault();
                    first.focus();
                }
            },
        }"
        x-init="$watch('mobileOpen', open => open && $nextTick(() => document.querySelector('[data-mobile-nav] button')?.focus()))"
        x-on:keydown.tab.window="trapMobileTab($event)"
    >

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

        {{-- Desktop sidebar: 256px expanded, 72px icon rail collapsed.
             The swap carries no width transition: animating `width` reflows the whole page, and
             every other collapse in the app (the mobile drawer, the table pins) is a snap too. --}}
        <aside
            class="fixed inset-y-0 start-0 z-(--z-header) hidden w-64 flex-col border-e border-fg-line bg-fg-canvas lg:flex"
            :class="collapsed ? 'w-[72px]' : 'w-64'"
        >
            <div class="flex h-16 items-center gap-2 border-b border-fg-line px-4" :class="collapsed ? 'justify-center' : ''">
                <x-app-logo :name="$shellSiteName" :href="route('dashboard')" collapsible wire:navigate />

                <button
                    type="button"
                    x-on:click="toggleCollapsed()"
                    class="ms-auto grid size-8 shrink-0 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk hover:text-fg-ink"
                    :class="collapsed ? 'ms-0' : ''"
                    :aria-label="collapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
                >
                    <x-ui.icon name="chevron-left" size="4" class="rtl:rotate-180" x-show="! collapsed" x-cloak />
                    <x-ui.icon name="chevron-right" size="4" class="rtl:rotate-180" x-show="collapsed" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-3" aria-label="{{ __('Main navigation') }}">
                @include('partials.navigation', ['rail' => true])
            </nav>

            <div class="border-t border-fg-line p-3">
                <x-desktop-user-menu :name="$shellUser?->name" :email="$shellUser?->email" :initials="$shellUser?->initials()" :settings-href="route('profile.edit')" :logout-href="route('logout')" collapsible />
            </div>
        </aside>

        {{-- Mobile drawer. --}}
        <div
            x-show="mobileOpen"
            x-cloak
            class="fixed inset-0 z-(--z-menu) lg:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Main navigation') }}"
            x-on:keydown.escape.window="mobileOpen && closeMenu()"
        >
            {{-- Both halves of the drawer are one gesture, so they are on one clock. `x-transition.opacity`
                 would have faded the scrim on Alpine's own default duration and easing while the panel ran on
                 the app's tokens — two timelines for one interaction, which is exactly the seam a seat feels
                 even when they cannot name it. --}}
            <div
                class="absolute inset-0 bg-fg-ink/45"
                x-on:click="closeMenu()"
                x-transition:enter="transition duration-(--t-drawer) ease-(--ease-drawer)"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition duration-(--t-base) ease-(--ease-standard)"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            ></div>

            {{--
                The one surface allowed past the UI duration ceiling. 180 ms is right for a menu that travels
                4 px; a panel crossing 85 % of a phone in the same time reads as a teleport with extra steps,
                because the eye cannot follow it. The curve is `--ease-drawer` rather than `--ease-emphasis`
                for the same reason: it decelerates hard into the screen edge, so the panel settles against the
                boundary it belongs to instead of floating to a stop in the middle of the viewport.

                Closing is faster than opening on purpose. Opening is the app showing the seat what it asked
                for; closing is the app getting out of the way of what they asked next.
            --}}
            <aside
                class="absolute inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col border-e border-fg-line bg-fg-surface shadow-fg-3"
                data-mobile-nav
                x-transition:enter="transition duration-(--t-drawer) ease-(--ease-drawer)"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition duration-(--t-base) ease-(--ease-standard)"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
            >
                <div class="flex h-16 items-center justify-between border-b border-fg-line px-4">
                    <x-app-logo :name="$shellSiteName" :href="route('dashboard')" wire:navigate />

                    <button
                        type="button"
                        x-on:click="closeMenu()"
                        class="grid size-8 touch:size-11 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk"
                        aria-label="{{ __('Close menu') }}"
                    >
                        <x-ui.icon name="x-mark" />
                    </button>
                </div>

                <nav class="flex-1 overflow-y-auto px-3 py-3">
                    @include('partials.navigation')
                </nav>

                <div class="border-t border-fg-line p-3">
                    <x-desktop-user-menu :name="$shellUser?->name" :email="$shellUser?->email" :initials="$shellUser?->initials()" :settings-href="route('profile.edit')" :logout-href="route('logout')" :collapsed="false" />
                </div>
            </aside>
        </div>

        {{-- Top bar: search, notifications, quick actions, theme toggle, user menu.
             Solid surface rather than a translucent one: a blur over a light page is the single detail that
             reads as a template, and the seam the bar needs is its hairline. Search sits on the left because
             it is where you go *to*, and the action group on the right is where you go *from*. --}}
        {{-- The offset and the rail's width are the same measurement, so they move on the same clock. An
             inline `style` carried its own literal 180 ms and its own curve, which is how a token change in
             palette.css silently left this element behind. --}}
        <div class="lg:ps-64 transition-[padding-inline-start] duration-(--t-base) ease-(--ease-standard)" :class="collapsed ? 'lg:ps-[72px]' : 'lg:ps-64'">
            <header class="sticky top-0 z-(--z-sticky) flex h-16 items-center gap-2 border-b border-fg-line bg-fg-surface px-4">
                <button
                    type="button"
                    class="grid size-9 touch:size-11 shrink-0 place-items-center rounded-fg-sm text-fg-ink-secondary hover:bg-fg-surface-sunk lg:hidden"
                    x-on:click="openMenu($el)"
                    data-test="mobile-menu-button"
                    aria-label="{{ __('Open menu') }}"
                >
                    <x-ui.icon name="bars-2" size="5" />
                </button>

                <x-search-button class="max-sm:hidden" />

                <div class="ms-auto flex items-center gap-1">
                    <x-quick-actions :actions="$shellCreateActions" />
                    <x-notifications-button :href="$shellAlertsHref" :count="$shellUnread" />
                    <x-theme-toggle />
                    {{-- Avatar only: the name and email are already in the drawer's footer, and the full
                         trigger measured 224 px in a 390 px top bar, which pushed the row 2 px past the
                         viewport and squeezed the menu button down to a 20 px square. --}}
                    <x-desktop-user-menu :name="$shellUser?->name" :email="$shellUser?->email" :initials="$shellUser?->initials()" :settings-href="route('profile.edit')" :logout-href="route('logout')" :collapsed="true" position="bottom" class="lg:hidden" />
                </div>
            </header>

            <main id="main" class="mx-auto w-full max-w-[1400px] p-4 lg:p-6">
                {{ $slot }}
            </main>
        </div>

        <x-ui.toast.group />
        <x-flash-toasts :flashes="$shellFlashes" />
    </body>
</html>
