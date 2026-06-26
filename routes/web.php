<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\VkController;
use App\Models\LandingPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Свежий CSRF-токен (анти-419 на чекауте: форма подтягивает токен текущей сессии перед сабмитом)
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))->name('csrf.token');

// Страница оформления заказа (Checkout)
Route::get('/checkout/{tariff}', [CheckoutController::class, 'show'])->name('checkout.show');

// --- НОВЫЕ РОУТЫ ДЛЯ ПРОМОКОДОВ ---
Route::post('/checkout/{tariff}/promo', [CheckoutController::class, 'applyPromo'])->name('checkout.promo');
Route::post('/checkout/{tariff}/promo/remove', [CheckoutController::class, 'removePromo'])->name('checkout.promo.remove');

// 1. РЕДИРЕКТ (чтобы старые ссылки работали)
Route::get('/promo/{slug}', function ($slug) {
    return redirect('/'.$slug, 301);
});

// --- ГЛАВНАЯ И АВТОРИЗАЦИЯ ---

// --- ИЗМЕНЕННЫЙ РОУТ ГЛАВНОЙ СТРАНИЦЫ (ВИТРИНА) ---
Route::get('/', function () {
    // Берем только опубликованные курсы, по 9 на страницу.
    // is_listed=false (например, страница записи вебинара) в витрину не попадает.
    $landings = LandingPage::where('is_active', true)
        ->where('is_listed', true)
        ->paginate(9);

    // Открытые занятия для витринной карусели: отобраны вручную через флаг show_on_main
    // (фильтрация is_free + is_published сидит в Lesson::scopeShownOnMain).
    $openLessons = \App\Models\Lesson::shownOnMain()
        ->with('course:id,slug,title')
        ->latest('lesson_date')
        ->get();

    return view('main', compact('landings', 'openLessons'));
});

// Витрина магазина курсов
Route::get('/online', [ShopController::class, 'index'])->name('shop.index');

// Страница одного курса
Route::get('/online/kursy/{course:slug}', [ShopController::class, 'show'])->name('shop.course.show');

// Редиректы со старых URL витрины (SEO + старые ссылки/закладки/реклама).
// Имена роутов сохранены, меняются только пути — поэтому route() ниже валиден.
// Специфичный /shop/course/* — ДО общего /shop, иначе общий перехватит.
Route::get('/shop/course/{slug}', fn ($slug) => redirect()->route('shop.course.show', $slug, 301));
Route::get('/shop', fn () => redirect()->route('shop.index', [], 301));

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
    Route::get('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

// Редирект со старого URL личного кабинета (вне auth-группы, чтобы старые
// закладки работали; имя student.dashboard сохранено — путь сменился на /dvaram).
Route::get('/cabinet', fn () => redirect()->route('student.dashboard', [], 301));

// ═══════════════════════════════════════════════════════════════
// ПУБЛИЧНЫЕ ДОКУМЕНТЫ (оферта, политика, согласия) — до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::get('/dokumenty/{slug}', [\App\Http\Controllers\DocController::class, 'show'])
    ->name('docs.show');

// ═══════════════════════════════════════════════════════════════
// СТАТЬИ (блог) — ВАЖНО: должно быть до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::prefix('s')->name('articles.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ArticleController::class, 'index'])
        ->name('index');

    Route::get('/{article:slug}', [\App\Http\Controllers\ArticleController::class, 'show'])
        ->name('show');
});

// --- СЕКРЕТ-ССЫЛКА ОБХОДА ТЕХОБСЛУЖИВАНИЯ (вне maintenance-группы) ---
Route::get('/maintenance-bypass/{secret}', function (string $secret) {
    $s = \App\Models\MarketingSetting::cached();
    abort_unless(
        $s && filled($s->student_maintenance_secret)
            && hash_equals((string) $s->student_maintenance_secret, $secret),
        404
    );

    return redirect()->route('student.dashboard')
        ->cookie('student_maintenance_bypass', $secret, 60 * 24 * 7); // неделя
})->middleware('auth')->name('maintenance.bypass');

// --- ЛИЧНЫЙ КАБИНЕТ СТУДЕНТА (ЗАЩИЩЕНО) ---
Route::middleware(['auth', 'track.activity', 'student.maintenance'])->group(function () {

    Route::get('/home', function () {
        $user = auth()->user();
        if ($user->is_admin) {
            return redirect('/admin');
        }

        return redirect()->route('student.dashboard');
    })->name('home');

    Route::get('/calendar', [StudentController::class, 'calendar'])->name('student.calendar');
    Route::get('/dvaram', [StudentController::class, 'dashboard'])->name('student.dashboard');

    Route::get('/open-lessons', [StudentController::class, 'openLessons'])->name('student.open-lessons');

    Route::get('/messages', [StudentController::class, 'messages'])->name('student.messages');

    Route::get('/course/{slug}', [StudentController::class, 'showCourse'])->name('student.course');
    Route::get('/course/{slug}/lesson/{lessonId}', [StudentController::class, 'showLesson'])->name('student.lesson');

    Route::post('/course/{slug}/lesson/{lessonId}/complete', [StudentController::class, 'completeLesson'])
        ->name('student.lesson.complete');

    Route::get('/course/{slug}/materials/download', [StudentController::class, 'downloadCourseMaterials'])
        ->name('student.course.materials.download');

    Route::post('/course/{slug}/lesson/{lessonId}/note', [StudentController::class, 'saveNote'])
        ->name('student.lesson.note');

    // Домашние задания: сдача студентом + контролируемое скачивание файлов
    Route::post('/course/{slug}/lesson/{lessonId}/homework', [\App\Http\Controllers\HomeworkController::class, 'store'])
        ->name('student.homework.store');
    Route::get('/homework/file/{file}', [\App\Http\Controllers\HomeworkController::class, 'download'])
        ->name('homework.file.download');

    Route::post('/api/heartbeat', [\App\Http\Controllers\Api\HeartbeatController::class, 'store'])
        ->name('activity.heartbeat');

    // P2P-перевод праны другому студенту (подарок).
    Route::post('/prana/transfer', [\App\Http\Controllers\PranaTransferController::class, 'transfer'])
        ->middleware('throttle:20,1')
        ->name('student.prana.transfer');

    // Магазин праны: покупка перка за прану.
    Route::post('/prana/redeem/{perk}', [\App\Http\Controllers\PranaShopController::class, 'redeem'])
        ->middleware('throttle:20,1')
        ->name('student.prana.redeem');

    Route::get('/certificate/{id}/download', [StudentController::class, 'downloadCertificate'])
        ->name('student.certificate.download');

    Route::get('/certificate/{id}/download/jpg', [StudentController::class, 'downloadCertificateImage'])
        ->name('student.certificate.download.jpg');

    Route::get('/admin/leads/export', [LeadController::class, 'export'])
        ->middleware('admin')
        ->name('leads.export');

    Route::get('/telegram/connect', [TelegramController::class, 'connect'])->name('telegram.connect');

    // Привязка VK через одноразовый токен (вместо сырого ?ref={user_id}) — см. VkController.
    Route::get('/vk/connect', [VkController::class, 'connect'])->name('vk.connect');

    // Отвязка мессенджера (TG/VK) из кабинета — кнопка «Отвязать»
    Route::post('/profile/messenger/{channel}/disconnect', [StudentController::class, 'disconnectMessenger'])
        ->whereIn('channel', ['telegram', 'vk'])
        ->name('student.messenger.disconnect');

    // Самостоятельная смена пароля студентом в кабинете
    Route::post('/profile/password', [AuthController::class, 'updatePassword'])
        ->name('student.password.update');
});

// --- ТЕХНИЧЕСКИЕ И ДЕБАГ МАРШРУТЫ ---

// БЕЗОПАСНОЕ СКАЧИВАНИЕ ФАЙЛОВ
Route::get('/force-download/{file}', function (string $file) {
    $safeFileName = basename($file); // защита от path traversal
    // Архивы сертификатов кладёт GenerateCertificatesArchive в подкаталог archives/.
    $path = 'archives/'.$safeFileName;

    if (! Storage::disk('public')->exists($path)) {
        abort(404, 'Файл не найден.');
    }

    return Storage::disk('public')->download($path);
})->middleware('auth')->name('force-download');

// Debug-маршрут удалён из production (см. BUGS_REPORT.md #1.1)

// --- ОТПРАВКА ФОРМЫ ---
Route::post('/leads/store', [LeadController::class, 'store'])->name('leads.store');
Route::get('/thank-you', function () {
    // Переносим flash на следующий request, чтобы F5 на странице
    // не сбрасывал состояние (дубликат vs новая заявка) и кнопки магнита.
    session()->reflash();

    return view('promo.thankyou');
})->name('thank.you');

// --- РОУТЫ ДЛЯ ТОЧКА БАНКА ---
// Перенес их выше роута-перехватчика {slug} для безопасности
Route::post('/payment/create', [PaymentController::class, 'createPayment'])->name('payment.create');
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/fail', [PaymentController::class, 'fail'])->name('payment.fail');

// Депозит (бронь курса) — отдельный POST, тот же эквайринг.
// Биндинг по slug — симметрично с /shop/course/{course:slug}.
// ВАЖНО: строго до catch-all /{slug} ниже.
// throttle:5,1 — публичный эндпоинт, защита от ботов, которые иначе могли бы
// насоздавать pending-платежей на чужие email со скоростью сети.
Route::post('/deposit/{course:slug}', [DepositController::class, 'create'])
    ->middleware('throttle:5,1')
    ->name('deposit.create');

// Пробное занятие — отдельный POST, тот же эквайринг. Строго до catch-all /{slug}.
Route::post('/trial/{course:slug}', [\App\Http\Controllers\TrialController::class, 'create'])
    ->middleware('throttle:5,1')
    ->name('trial.create');

// --- РЕДАКТОР ЛЕКЦИЙ (Filament-панель /editor) ---
Route::middleware(['web', 'auth'])
    ->prefix('editor/lectures/{draft}')
    ->name('editor.lecture.')
    ->group(function () {
        Route::get('preview', [\App\Http\Controllers\Editor\LectureDraftController::class, 'preview'])
            ->name('preview');
        Route::get('asset/{path}', [\App\Http\Controllers\Editor\LectureDraftController::class, 'asset'])
            ->where('path', '.*')
            ->name('asset');
        Route::post('patch', [\App\Http\Controllers\Editor\LectureDraftController::class, 'patch'])
            ->name('patch');
    });

// --- SITEMAP ДЛЯ ПОИСКОВЫХ РОБОТОВ ---
// ВАЖНО: до catch-all /{slug}
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// --- ВЕРИФИКАЦИЯ СЕРТИФИКАТА (ссылка из QR-кода) ---
// ВАЖНО: до catch-all /{slug}, публичный без auth.
Route::get('/verify/{number}', [\App\Http\Controllers\CertificateVerificationController::class, 'show'])
    ->name('certificate.verify');

// --- КОРОТКАЯ ССЫЛКА НА КАРТОЧКУ СТУДЕНТА (для заметок в Telegram-контактах) ---
// ВАЖНО: до catch-all /{slug}. Префикс /u (а не /s — тот занят блогом, prefix('s')).
// Ведёт на режим ПРОСМОТРА карточки; доступ под guard'ом Filament-панели admin.
Route::get('/u/{user}', function (\App\Models\User $user) {
    return redirect(\App\Filament\Resources\UserResource::getUrl('view', ['record' => $user]));
})->whereNumber('user')->name('student.shortlink');

// --- СОЦИАЛЬНАЯ АВТОРИЗАЦИЯ (Socialite) ---
// ВАЖНО: до catch-all /{slug}. Провайдер включается заданием client_id в .env,
// иначе redirect/callback отдают 404 (см. SocialAuthService::isEnabled).
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->name('social.callback');

// --- ЛЕНДИНГИ (БЕЗ ПРЕФИКСА) ---
// ВАЖНО: Этот маршрут ВСЕГДА строго в самом низу!
Route::get('/{slug}', [PromoController::class, 'show'])->name('promo.show');
