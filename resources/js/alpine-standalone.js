/*
 * 152-ФЗ: Alpine со своего сервера вместо cdn.jsdelivr.net (CDN получал IP
 * каждого посетителя). Только для страниц без Livewire (promo, articles,
 * main, partners) — там, где раньше стоял <script defer src="…/alpinejs@3…">.
 * В лейаутах с Livewire Alpine уже приходит вместе с ним — этот файл туда
 * не подключать (два экземпляра Alpine ломают x-data).
 */
import Alpine from 'alpinejs';

if (!window.Alpine) {
    window.Alpine = Alpine;
    // Модуль Vite выполняется после разбора документа (как defer) — то же
    // время старта, что и у прежнего CDN-скрипта.
    Alpine.start();
}
