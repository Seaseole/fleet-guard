<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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

        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-100 focus:rounded-fg-md focus:bg-accent focus:px-3 focus:py-2 focus:text-sm focus:text-on-accent"
        >{{ __('Skip to content') }}</a>

        {{-- Desktop sidebar: 256px expanded, 72px icon rail collapsed. --}}
        <aside
            class="fixed inset-y-0 start-0 z-40 hidden w-64 flex-col border-e border-fg-line bg-fg-surface transition-[width] duration-(--t-base) ease-(--ease-standard) lg:flex"
            :class="collapsed ? 'w-[72px]' : 'w-64'"
        >
            <div class="flex h-14 items-center gap-2 border-b border-fg-line px-4">
                <x-app-logo :href="route('dashboard')" collapsible wire:navigate />

                <button
                    type="button"
                    x-on:click="toggleCollapsed()"
                    class="ms-auto grid size-8 shrink-0 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk hover:text-fg-ink"
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
                <x-desktop-user-menu collapsible />
            </div>
        </aside>

        {{-- Mobile drawer. --}}
        <div
            x-show="mobileOpen"
            x-cloak
            class="fixed inset-0 z-50 lg:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Main navigation') }}"
            x-on:keydown.escape.window="mobileOpen && closeMenu()"
        >
            <div
                class="absolute inset-0 bg-fg-ink/45"
                x-on:click="closeMenu()"
                x-transition.opacity
            ></div>

            <aside
                class="absolute inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col border-e border-fg-line bg-fg-surface shadow-fg-3"
                data-mobile-nav
                x-transition:enter="transition duration-(--t-base) ease-(--ease-emphasis)"
                x-transition:enter-start="-translate-x-full"
                x-transition:leave="transition duration-(--t-fast)"
                x-transition:leave-end="-translate-x-full"
            >
                <div class="flex h-14 items-center justify-between border-b border-fg-line px-4">
                    <x-app-logo :href="route('dashboard')" wire:navigate />

                    <button
                        type="button"
                        x-on:click="closeMenu()"
                        class="grid size-8 place-items-center rounded-fg-sm text-fg-ink-muted hover:bg-fg-surface-sunk"
                        aria-label="{{ __('Close menu') }}"
                    >
                        <x-ui.icon name="x-mark" />
                    </button>
                </div>

                <nav class="flex-1 overflow-y-auto px-3 py-3">
                    @include('partials.navigation')
                </nav>

                <div class="border-t border-fg-line p-3">
                    <x-desktop-user-menu :collapsed="false" />
                </div>
            </aside>
        </div>

        {{-- Top bar: search, notifications, quick actions, theme toggle, user menu. --}}
        <div class="lg:ps-64" :class="collapsed ? 'lg:ps-[72px]' : 'lg:ps-64'" style="transition: padding-inline-start 180ms cubic-bezier(.2,0,.1,1)">
            <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-fg-line bg-fg-surface/95 px-4 backdrop-blur">
                <button
                    type="button"
                    class="grid size-9 place-items-center rounded-fg-sm text-fg-ink-secondary hover:bg-fg-surface-sunk lg:hidden"
                    x-on:click="openMenu($el)"
                    data-test="mobile-menu-button"
                    aria-label="{{ __('Open menu') }}"
                >
                    <x-ui.icon name="bars-2" size="5" />
                </button>

                <div class="ms-auto flex items-center gap-1">
                    <x-quick-actions />
                    <x-notifications-button />
                    <x-theme-toggle />
                    <x-desktop-user-menu :collapsed="false" position="bottom" class="lg:hidden" />
                </div>
            </header>

            <main id="main" class="mx-auto w-full max-w-[1400px] p-4 lg:p-6">
                {{ $slot }}
            </main>
        </div>

        <x-ui.toast.group />
        <x-flash-toasts />
    </body>
</html>
