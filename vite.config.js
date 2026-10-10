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
                'resources/css/fonts.css', // 152-ФЗ — шрифты и Font Awesome со своего сервера
                'resources/js/alpine-standalone.js', // 152-ФЗ — Alpine без jsdelivr (страницы без Livewire)
                'resources/js/devanagari-board.js', // H6327 — ленивый чанк Excalidraw (React) для доски прописи
            ],
            refresh: true,
        }),
    ],
});