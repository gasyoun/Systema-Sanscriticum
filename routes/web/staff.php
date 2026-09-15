<?php

// H4516 — staff domain (split from routes/web.php; lines 928-951).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// --- ТЕХНИЧЕСКИЕ И ДЕБАГ МАРШРУТЫ ---

// БЕЗОПАСНОЕ СКАЧИВАНИЕ ФАЙЛОВ
Route::get('/force-download/{file}', function (string $file) {
    // Только персонал: архивы сертификатов групп генерят и качают админы/редакторы/
    // преподаватели из Filament. Студенту тут делать нечего — раньше любой
    // залогиненный мог скачать чужой архив по (предсказуемому) имени (IDOR).
    $u = auth()->user();
    abort_unless($u && ($u->is_admin || $u->is_lecture_editor || $u->teacher_id), 403);

    $safeFileName = basename($file); // защита от path traversal
    // Архивы сертификатов кладёт GenerateCertificatesArchive в приватный
    // каталог archives/ на disk('local') (H3310) — раньше это был публичный
    // диск, и файл дублировался по прямому /storage/archives/... URL.
    $path = 'archives/'.$safeFileName;

    if (! Storage::disk('local')->exists($path)) {
        abort(404, 'Файл не найден.');
    }

    return Storage::disk('local')->download($path);
})->middleware('auth')->name('force-download');

// Debug-маршрут удалён из production (см. BUGS_REPORT.md #1.1)
