<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DictionaryPageController;
use App\Http\Controllers\DocController;
use App\Http\Controllers\GrammarLabController;
use App\Http\Controllers\GuestRegisterController;
use App\Http\Controllers\InstituteController;
use App\Http\Controllers\InstituteDonateController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PublicCabinetGuideController;
use App\Http\Controllers\ReadingPackController;
use App\Http\Controllers\RecordingGateController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\TransliterateController;
use App\Http\Controllers\VisualDcsController;
use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::post('/shop/login', [AuthController::class, 'shopLogin'])
    ->middleware('throttle:5,1')
    ->name('shop.login');

Route::post('/shop/logout', [AuthController::class, 'shopLogout'])
    ->name('shop.logout');

// --- ВОССТАНОВЛЕНИЕ ПАРОЛЯ (для незалогиненных) ---
Route::middleware('guest')->group(function () {
    // H3643 guest /register. Flag OFF returns 404 inside the controller.
    Route::get('/register', [GuestRegisterController::class, 'show'])->name('register');
    Route::post('/register', [GuestRegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.post');

    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

// Редирект со старого URL личного кабинета (вне auth-группы, чтобы старые
// закладки работали; имя student.dashboard сохранено — путь сменился на /dvaram).
Route::get('/cabinet', fn () => redirect()->route('student.dashboard', [], 301));

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

// Институт исследования санскрита — витрина ДПП ПК «Санскрит» 72 ч (заявочная форма).
Route::get('/institut', [InstituteController::class, 'landing'])->name('institute.landing');
Route::post('/institut/zayavka', [InstituteController::class, 'apply'])
    ->middleware('throttle:6,1')
    ->name('institute.apply');

// Меценаты Института — страница добровольных пожертвований (ст. 582 ГК,
// свободная сумма, без встречного пакета благ; реквизиты — config/institute.php)
// + публичный реестр благодарностей меценатам (план института N3).
Route::get('/mecenaty', [InstituteDonateController::class, 'page'])->name('institute.mecenaty');

// Онлайн-приём пожертвований через Точку (план института N2). Контроллер сам
// 404-ит при institute.donations_enabled=false — тёмный деплой безопасен.
Route::post('/mecenaty/donate', [InstituteDonateController::class, 'donate'])
    ->middleware('throttle:6,1')
    ->name('institute.donate');

// --- СЕКРЕТ-ССЫЛКА ОБХОДА ТЕХОБСЛУЖИВАНИЯ (вне maintenance-группы) ---
Route::get('/maintenance-bypass/{secret}', function (string $secret) {
    $s = MarketingSetting::cached();
    abort_unless(
        $s && filled($s->student_maintenance_secret)
            && hash_equals((string) $s->student_maintenance_secret, $secret),
        404
    );

    return redirect()->route('student.dashboard')
        ->cookie('student_maintenance_bypass', $secret, 60 * 24 * 7); // неделя
})->middleware('auth')->name('maintenance.bypass');

// H4396 — серверные ворота видеопейлоада: страница урока больше не несёт
// сырых unlisted-ID, плеер грузит этот маршрут, контроллер на КАЖДУЮ загрузку
// проверяет грант/группу/оплату/H3916-членство и только тогда отдаёт 302 на
// embed. ВНЕ auth-группы: is_free/is_preview легитимно отдаются гостю
// (публичный «пример урока» — единственная точка правды ShopController::preview),
// всё платное контроллер закрывает 404 сам. До catch-all /{slug}.
Route::get('/c/{slug}/u/{lessonId}/video/{player}', [RecordingGateController::class, 'show'])
    ->middleware('course.canonical')
    ->whereIn('player', ['youtube', 'rutube', 'kinescope', 'video'])
    ->name('student.recording.gate');

Route::get('/otzyvy', [ShopController::class, 'testimonialsLibrary'])
    ->name('shop.testimonials.library');
