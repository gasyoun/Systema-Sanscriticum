import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/article.css', // ← стили страницы статьи
                'resources/js/transliterate.js', // H1463 — /transliterate playground
                'resources/js/offline/spike.ts', // H2597 — Wave 0 capability harness (not a user-facing entry)
            ],
            refresh: true,
        }),
    ],
});
