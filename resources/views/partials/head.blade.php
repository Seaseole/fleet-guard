<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Fleet Guard') : config('app.name', 'Fleet Guard') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@livewireStyles
@livewireScriptConfig
@vite(['resources/css/app.css', 'resources/js/app.js'])

{{--
    Theme bootstrap, replacing the starter kit's @fluxAppearance. The stored
    preference is applied to <html> before first paint, so a dark-mode user never
    sees a white flash. Preference is 'light' | 'dark' | 'system' in localStorage.
--}}
<script>
    (() => {
        const apply = (mode) => {
            const dark = mode === 'dark'
                || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
        };

        window.FleetGuardTheme = {
            get: () => localStorage.getItem('fg_theme') ?? 'system',
            set: (mode) => {
                localStorage.setItem('fg_theme', mode);
                apply(mode);
            },
        };

        apply(window.FleetGuardTheme.get());

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (window.FleetGuardTheme.get() === 'system') {
                apply('system');
            }
        });
    })();
</script>
