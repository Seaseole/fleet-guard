<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.App\Domain\Kernel\Tenancy\SiteName::current() : App\Domain\Kernel\Tenancy\SiteName::current() }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@livewireStyles
@livewireScriptConfig
@vite(['resources/css/app.css', 'resources/js/app.js'])

{{--
    Theme bootstrap, replacing the starter kit's @fluxAppearance.

    Two layers, resolved in this order: a **personal** choice stored by this browser, and the
    **company default** the Owner sets in Application settings, handed over as `data-theme-default` on
    the root element and also rendered as the initial `dark` class. A fresh device therefore opens in the
    company's theme with nothing to configure; a seat that flips it keeps that flip across every page of
    the app, because the store is this browser's and the resolution runs on every document.

    It has to happen before first paint, which a server round-trip per page cannot do — that is why the
    personal layer stays client-side rather than joining the company settings blob.
--}}
<script>
    (() => {
        const KEY = 'fg_theme';
        const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

        const resolve = (mode) => mode === 'dark'
            || (mode === 'system' && darkQuery.matches);

        const apply = (mode) => {
            document.documentElement.classList.toggle('dark', resolve(mode));

            // Charts read their colours out of these custom properties, and a canvas is not a CSS consumer — it
            // has to be told. Every theme path funnels through here, so one dispatch covers a personal choice, a
            // company default, the OS scheme and a `wire:navigate` re-apply.
            document.dispatchEvent(new CustomEvent('fg:theme'));
        };

        const companyDefault = () => document.documentElement.dataset.themeDefault || 'system';

        window.FleetGuardTheme = {
            /** This browser's own choice, or null when the seat has never made one. */
            get: () => localStorage.getItem(KEY),
            /** What is actually being shown: personal choice, else the company default. */
            effective: () => localStorage.getItem(KEY) ?? companyDefault(),
            isPersonal: () => localStorage.getItem(KEY) !== null,
            set: (mode) => {
                localStorage.setItem(KEY, mode);
                apply(mode);
            },
            /** Hand the decision back to the company — the theme becomes the default again. */
            clear: () => {
                localStorage.removeItem(KEY);
                apply(companyDefault());
            },
        };

        apply(window.FleetGuardTheme.effective());

        // `wire:navigate` keeps the page alive and morphs the shell, which replaces this element's class
        // with the one the *next* document was rendered with — a server answer that knows neither the
        // personal choice nor the device's own scheme. Without this hook the theme holds until the first
        // SPA navigation and then quietly reverts, which is exactly what the seat reports as "it doesn't
        // stay". Livewire's own event is the seam: it fires after the swap has settled.
        document.addEventListener('livewire:navigated', () => {
            apply(window.FleetGuardTheme.effective());
        });

        // Only a `system` layer follows the device: a seat that chose Light stays Light when the
        // sunset flips the OS to dark, and a company that chose Dark stays Dark in the yard.
        darkQuery.addEventListener('change', () => {
            if (window.FleetGuardTheme.effective() === 'system') {
                apply('system');
            }
        });
    })();
</script>
