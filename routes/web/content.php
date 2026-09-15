<?php

// H4516 — content domain (split from routes/web.php; lines 417-500).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\DictionaryPageController;
use App\Http\Controllers\DocController;
use App\Http\Controllers\GrammarLabController;
use App\Http\Controllers\PublicCabinetGuideController;
use App\Http\Controllers\ReadingPackController;
use App\Http\Controllers\TransliterateController;
use App\Http\Controllers\VisualDcsController;
use Illuminate\Support\Facades\Route;

// ═══════════════════════════════════════════════════════════════
// ПУБЛИЧНЫЕ ДОКУМЕНТЫ (оферта, политика, согласия) — до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::get('/dokumenty/{slug}', [DocController::class, 'show'])
    ->name('docs.show');

// Условия возврата (H1288) — порядок отказа из оферты (приложение №1),
// изложенный по-русски; сами условия задает только PDF оферты.
Route::view('/vozvrat', 'docs.vozvrat')->name('refund.show');

// FAQ: как сдавать ДЗ — публично (удобно для ссылки в чат группы; без входа).
Route::view('/faq/dz', 'faq.dz')->name('faq.dz');

// FAQ: способы оплаты, рассрочка, что делать, если платёж не проходит — публично
// (H2060, linked from student.access recovery CTA behind payment_recovery_cta).
Route::view('/faq/payment', 'faq.payment')->name('faq.payment');

// Публичный гид личного кабинета (H3499) — БЕЗ auth, для ещё не вошедших:
// рассылки students:send-login-invites, анонсы в Telegram, скрипты куратора.
// Тот же источник, что кабинетный /dvaram/help. Строго до catch-all /{slug}.
Route::get('/help/kabinet', [PublicCabinetGuideController::class, 'show'])
    ->name('help.cabinet-guide');

// Публичная «сайт жив?» для учеников (VPN vs наш сервер + @rusamskrtam).
// До catch-all /{slug}. Зеркало на GitHub Pages: /uptime/ в корне репо.
Route::view('/uptime', 'uptime')->name('uptime.show');

// ═══════════════════════════════════════════════════════════════
// СТАТЬИ (блог) — ВАЖНО: должно быть до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::prefix('s')->name('articles.')->group(function () {
    Route::get('/', [ArticleController::class, 'index'])
        ->name('index');

    // Словесные пути вместо ?category=/?q= (H3093-паттерн, теперь на статьи).
    // Строгий where() + регистрация ДО /{article:slug} — иначе однословный
    // /s/rubrika без значения дальше решился бы implicit binding как slug статьи.
    Route::get('/{facets}', [ArticleController::class, 'index'])
        ->where('facets', '(rubrika|poisk)(/.+)*')
        ->name('index.facets');

    Route::get('/{article:slug}', [ArticleController::class, 'show'])
        ->name('show');
});

// ═══════════════════════════════════════════════════════════════
// СЛОВАРЬ (публичные словарные entity-страницы, SEO P2 / H204)
// ВАЖНО: строго до catch-all /{slug}. Wave 0 — все страницы noindex,follow.
// ═══════════════════════════════════════════════════════════════
Route::prefix('slovar')->name('slovar.')->group(function () {
    Route::get('/', [DictionaryPageController::class, 'index'])
        ->name('index');

    // slug — по заголовочному слову (не по строке×словарь), см. решение D3.
    Route::get('/{slug}', [DictionaryPageController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('show');
});

// ═══════════════════════════════════════════════════════════════
// ЧТЕНИЕ (kosha last-mile pipeline, Hop A — H959). За фича-флагом
// features.kosha_reader, ВЫКЛ по умолчанию (404 пока не включен).
// ═══════════════════════════════════════════════════════════════
Route::get('/reading/kosha-demo', [ReadingPackController::class, 'show'])
    ->name('reading.kosha-demo');

// ═══════════════════════════════════════════════════════════════
// H1463 — Sanskrit-HUB L5 /transliterate playground (Workstream A v0).
// features.hub_transliterate, ВЫКЛ по умолчанию (404 пока не включен).
// H2763 — public /sanskritorium wrapper; always 200, same view+JS.
// ═══════════════════════════════════════════════════════════════
Route::get('/transliterate', [TransliterateController::class, 'show'])
    ->name('hub.transliterate');
Route::get('/sanskritorium', [TransliterateController::class, 'sanskritorium'])
    ->name('hub.sanskritorium');

// H2482 — public bounded VisualDCS preview (flag per surface, 404 when OFF).
Route::get('/visualdcs/{surface}/preview', [VisualDcsController::class, 'preview'])
    ->where('surface', 'verb|nominal|passage')
    ->name('visualdcs.preview');

// H2493 — Grammar Lab marketing landing (no topic/vector payload).
Route::get('/grammar-lab', [GrammarLabController::class, 'landing'])
    ->name('grammar-lab.landing');
