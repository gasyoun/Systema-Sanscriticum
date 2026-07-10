<?php

use App\Filament\Resources\UserResource;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DebtPaymentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\DictionaryPageController;
use App\Http\Controllers\DocController;
use App\Http\Controllers\Editor\LectureDraftController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\JoinClassController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MarathonController;
use App\Http\Controllers\MaterialsController;
use App\Http\Controllers\NewsletterSubscribeController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaypalClaimController;
use App\Http\Controllers\PranaShopController;
use App\Http\Controllers\PranaTransferController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SrsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TrialController;
use App\Http\Controllers\VkController;
use App\Models\LandingPage;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\RoleGate;
use App\Support\TrajectoryPaths;
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
// throttle на apply — иначе публичный эндпоинт превращается в оракул для
// перебора валидных промокодов (по 10 попыток в минуту хватит легитимному юзеру).
Route::post('/checkout/{tariff}/promo', [CheckoutController::class, 'applyPromo'])
    ->middleware('throttle:10,1')
    ->name('checkout.promo');
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
    $openLessons = Lesson::shownOnMain()
        ->with('course:id,slug,title')
        ->latest('lesson_date')
        ->get();

    // Трёхшаговая траектория обучения в hero (H431, Phase 1 п.1) — резолвится
    // в реальные курсы по паттерну title, см. App\Support\TrajectoryPaths.
    $trajectorySteps = TrajectoryPaths::resolve();

    // Отзывы для proof-блока (H431, Phase 1 п.3) — та же общесайтовая полоса
    // избранных отзывов, что и в ShopController::index.
    $featuredTestimonials = Testimonial::featured()->latest('id')->limit(6)->get();

    return view('main', compact('landings', 'openLessons', 'trajectorySteps', 'featuredTestimonials'));
});

// Витрина магазина курсов
Route::get('/online', [ShopController::class, 'index'])->name('shop.index');

// «С чего начать» — вводная страница новичка: лесенка продуктов + квиз подбора
// курса + уровни (H323, beginner on-ramp).
Route::get('/online/s-chego-nachat', [ShopController::class, 'start'])->name('shop.start');

// «Материалы» — журнальный хаб бесплатного контента над магазином (H387,
// паттерн Arzamas): статьи + бесплатные беседы + preview-уроки одной сеткой
// типизированных карточек. Блог остаётся на /s — здесь только агрегатор.
Route::get('/online/materialy', [MaterialsController::class, 'index'])->name('shop.materials');

// «Консультация по онлайн-курсам ОРС» — 3-дневный диагностический марафон,
// верхний вход воронки (H440, Phase 1: landing + capture). Evergreen —
// личные дни-0..3 от day0_started_at, НЕ общий календарь потока.
Route::get('/online/konsultaciya', [MarathonController::class, 'show'])->name('marathon.show');
Route::post('/online/konsultaciya', [MarathonController::class, 'register'])->name('marathon.register');
// H471 Phase 4 — ₽500 «с проверкой» track checkout.
Route::post('/online/konsultaciya/pay', [MarathonController::class, 'pay'])->name('marathon.pay');
// H483 Phase 3b — Day 1/2 tap-choice recognition pages, keyed by the lead's
// existing magnet_token (no new token needed).
Route::get('/online/konsultaciya/day/{day}/{token}', [MarathonController::class, 'day'])
    ->where('day', '[12]')->name('marathon.day');
Route::post('/online/konsultaciya/day/{day}/{token}/complete', [MarathonController::class, 'completeDay'])
    ->where('day', '[12]')->name('marathon.day.complete');

// Страница одного курса
Route::get('/online/kursy/{course:slug}', [ShopController::class, 'show'])->name('shop.course.show');

// Публичный «Пример урока»: отдаёт ТОЛЬКО preview-урок этого курса (is_preview),
// без auth. Никакого lesson-id в URL — гость не может запросить произвольный урок.
Route::get('/online/kursy/{course:slug}/preview', [ShopController::class, 'preview'])->name('shop.course.preview');

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

// ═══════════════════════════════════════════════════════════════
// СТАТЬИ (блог) — ВАЖНО: должно быть до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::prefix('s')->name('articles.')->group(function () {
    Route::get('/', [ArticleController::class, 'index'])
        ->name('index');

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
    Route::post('/calendar/feed/regenerate', [CalendarFeedController::class, 'regenerate'])
        ->name('student.calendar.feed.regenerate');
    Route::get('/dvaram', [StudentController::class, 'dashboard'])->name('student.dashboard');

    Route::get('/open-lessons', [StudentController::class, 'openLessons'])->name('student.open-lessons');

    // SRS-карточки (H211, Wave 1) — маршрут появляется только при включённом
    // флаге srs.enabled (в проде OFF). Пункт меню в layouts.student — под тем же условием.
    if (config('srs.enabled')) {
        Route::get('/dvaram/srs', [SrsController::class, 'review'])
            ->name('student.srs');
    }

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
    Route::post('/course/{slug}/lesson/{lessonId}/homework', [HomeworkController::class, 'store'])
        ->name('student.homework.store');
    Route::get('/homework/file/{file}', [HomeworkController::class, 'download'])
        ->name('homework.file.download');

    Route::post('/api/heartbeat', [HeartbeatController::class, 'store'])
        ->name('activity.heartbeat');

    // Самообслуживание должника: студент сам гасит согласованную рассрочку/обещание.
    // Плоский долг «не продлил» идёт штатным /checkout/{tariff} (см. DebtPaymentResolver).
    Route::post('/debt/promise/{promise}/pay', [DebtPaymentController::class, 'payPromise'])
        ->name('student.debt.promise.pay');
    Route::post('/debt/promise/{promise}/reschedule', [DebtPaymentController::class, 'reschedule'])
        ->name('student.debt.promise.reschedule');
    Route::post('/debt/course/{course}/pay-all', [DebtPaymentController::class, 'payAll'])
        ->name('student.debt.course.pay-all');
    Route::post('/debt/course/{course}/pay-bundle', [DebtPaymentController::class, 'payBundle'])
        ->name('student.debt.course.pay-bundle');

    // P2P-перевод праны другому студенту (подарок).
    Route::post('/prana/transfer', [PranaTransferController::class, 'transfer'])
        ->middleware('throttle:20,1')
        ->name('student.prana.transfer');

    // Магазин праны: покупка перка за прану.
    Route::post('/prana/redeem/{perk}', [PranaShopController::class, 'redeem'])
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
    // Только персонал: архивы сертификатов групп генерят и качают админы/редакторы/
    // преподаватели из Filament. Студенту тут делать нечего — раньше любой
    // залогиненный мог скачать чужой архив по (предсказуемому) имени (IDOR).
    $u = auth()->user();
    abort_unless($u && ($u->is_admin || $u->is_lecture_editor || $u->teacher_id), 403);

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

// --- ПОДПИСКА НА РАССЫЛКУ (H324) — email-only → кабинет + magic-link + магниты.
// Оба маршрута самогейтятся по фича-флагу newsletter_subscribe (404 при OFF).
// Строго до catch-all /{slug} ниже. Публичные; троттлинг в контроллере/middleware.
Route::post('/subscribe', [NewsletterSubscribeController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('newsletter.subscribe');
Route::get('/magic/{token}', [NewsletterSubscribeController::class, 'magic'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('newsletter.magic');
Route::get('/thank-you', function () {
    // Переносим flash на следующий request, чтобы F5 на странице
    // не сбрасывал состояние (дубликат vs новая заявка) и кнопки магнита.
    session()->reflash();

    return view('promo.thankyou');
})->name('thank.you');

// --- РОУТЫ ДЛЯ ТОЧКА БАНКА ---
// Перенес их выше роута-перехватчика {slug} для безопасности
// throttle:5,1 — как у deposit/trial: публичный приём email + создание платежа,
// защита от ботов (спам pending-платежей, enumeration email, злоупотребление API Точки).
Route::post('/payment/create', [PaymentController::class, 'createPayment'])
    ->middleware('throttle:5,1')
    ->name('payment.create');
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
Route::post('/trial/{course:slug}', [TrialController::class, 'create'])
    ->middleware('throttle:5,1')
    ->name('trial.create');

// Оплата из-за рубежа (PayPal): форма-заявка студента + приём. Автосписания нет —
// платёж ложится pending и сверяется вручную в админке. Строго до catch-all /{slug}.
// throttle:5,1 — публичный приём email + создание pending-платежа (защита от ботов).
Route::get('/paypal/{tariff}', [PaypalClaimController::class, 'show'])
    ->name('paypal.claim.show');
Route::post('/paypal/{tariff}', [PaypalClaimController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('paypal.claim.store');

// Приватный чек PayPal-заявки: только персонал (сверка платежа в админке).
// Диск 'local' (не public) — скрин может содержать личные/платёжные данные.
Route::get('/admin/payments/{payment}/paypal-proof', function (Payment $payment) {
    $u = auth()->user();
    abort_unless($u && $u->is_admin, 403);
    abort_unless(
        filled($payment->proof_path) && Storage::disk('local')->exists($payment->proof_path),
        404
    );

    return Storage::disk('local')->download($payment->proof_path);
})->middleware('auth')->name('paypal.proof');

// Скачивание планировочных шаблонов «Нескучных финансов» (Финмодель, Бюджет,
// План доходов/расходов) — гибридная стратегия H207: живые отчёты в панели +
// эти workbooks вручную. Доступ — админ ИЛИ бухгалтер (+ супер-админ).
// Имена берём из белого списка, чтобы исключить обход каталога.
Route::get('/admin/finance-templates/{name}', function (string $name) {
    abort_unless(RoleGate::finance(), 403);

    $catalog = [
        'finmodel' => ['file' => 'finmodel.xlsx', 'as' => 'НФ — Финансовая модель.xlsx'],
        'budget' => ['file' => 'budget.xlsx', 'as' => 'НФ — Бюджет.xlsx'],
        'plan-income-expense' => ['file' => 'plan-income-expense.xlsx', 'as' => 'НФ — План доходов и расходов.xlsx'],
    ];

    abort_unless(isset($catalog[$name]), 404);

    $path = 'finance-templates/'.$catalog[$name]['file'];
    abort_unless(Storage::disk('local')->exists($path), 404);

    return Storage::disk('local')->download($path, $catalog[$name]['as']);
})->middleware('auth')->name('finance.template');

// --- РЕДАКТОР ЛЕКЦИЙ (Filament-панель /editor) ---
Route::middleware(['web', 'auth'])
    ->prefix('editor/lectures/{draft}')
    ->name('editor.lecture.')
    ->group(function () {
        Route::get('preview', [LectureDraftController::class, 'preview'])
            ->name('preview');
        Route::get('asset/{path}', [LectureDraftController::class, 'asset'])
            ->where('path', '.*')
            ->name('asset');
        Route::post('patch', [LectureDraftController::class, 'patch'])
            ->name('patch');
    });

// --- SITEMAP ДЛЯ ПОИСКОВЫХ РОБОТОВ ---
// ВАЖНО: до catch-all /{slug}
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// --- ВЕРИФИКАЦИЯ СЕРТИФИКАТА (ссылка из QR-кода) ---
// ВАЖНО: до catch-all /{slug}, публичный без auth.
Route::get('/verify/{number}', [CertificateVerificationController::class, 'show'])
    ->name('certificate.verify');

// --- КОРОТКАЯ ССЫЛКА НА КАРТОЧКУ СТУДЕНТА (для заметок в Telegram-контактах) ---
// ВАЖНО: до catch-all /{slug}. Префикс /u (а не /s — тот занят блогом, prefix('s')).
// Ведёт на режим ПРОСМОТРА карточки; доступ под guard'ом Filament-панели admin.
Route::get('/u/{user}', function (User $user) {
    return redirect(UserResource::getUrl('view', ['record' => $user]));
})->whereNumber('user')->name('student.shortlink');

// --- ПЕРСОНАЛЬНЫЙ iCAL/WEBCAL-ФИД РАСПИСАНИЯ (Google Calendar Phase 1) ---
// ВАЖНО: до catch-all /{slug}. Публичный: доступ по токену в URL, не по сессии
// (Google сам опрашивает ссылку) — см. docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md.
Route::get('/calendar/feed/{user}/{token}.ics', [CalendarFeedController::class, 'show'])
    ->whereNumber('user')->name('student.calendar.feed');

// --- ТРЕКИНГ-РЕДИРЕКТ «ПОДКЛЮЧИТЬСЯ К ЗАНЯТИЮ» (учёт посещаемости) ---
// ВАЖНО: до catch-all /{slug}. Публичный: кабинетная ссылка ловит юзера из сессии,
// бот/напоминания приходят подписанным URL с user id (внутри JoinClassController).
Route::get('/class/{schedule}/join', [JoinClassController::class, 'join'])
    ->whereNumber('schedule')->name('class.join');

// --- СОЦИАЛЬНАЯ АВТОРИЗАЦИЯ (Socialite) ---
// ВАЖНО: до catch-all /{slug}. Провайдер включается заданием client_id в .env,
// иначе redirect/callback отдают 404 (см. SocialAuthService::isEnabled).
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('social.callback');

// --- ЛЕНДИНГИ (БЕЗ ПРЕФИКСА) ---
// ВАЖНО: Этот маршрут ВСЕГДА строго в самом низу!
// === ПАРТНЁРСКАЯ (АГЕНТСКАЯ) ПРОГРАММА (за флагом config/partner.php) ===
// Публичный лендинг с условиями + приём заявок. Контроллер сам отдаёт 404,
// когда программа выключена. throttle на регистрацию — публичный приём формы.
Route::get('/partners', [PartnerController::class, 'landing'])->name('partners.landing');
// Чистая (SEO-friendly, без «?») партнёрская ссылка: /mitram/<КОД> → сессия + редирект на /.
Route::get('/mitram/{code}', [PartnerController::class, 'track'])->name('partners.track');
Route::post('/partners/register', [PartnerController::class, 'register'])
    ->middleware('throttle:10,1')
    ->name('partners.register');
Route::get('/partners/{code}', [PartnerController::class, 'registered'])->name('partners.registered');

Route::get('/{slug}', [PromoController::class, 'show'])->name('promo.show');
