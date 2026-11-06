import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('IBM Plex Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                }),
                // The Display voice for a view's nameplate (`--font-display`). Condensed, because the
                // role is signage — a plate, a header band, a tachograph print — not prose. Same family
                // as the UI face, so the nameplate and the labels under it cannot drift out of tune.
                bunny('IBM Plex Sans Condensed', {
                    weights: [600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
