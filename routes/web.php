<?php

use App\Filament\Resources\UserResource;
use App\Http\Controllers\AccountantGuideShotController;
use App\Http\Controllers\AdminLoginLinkController;
use App\Http\Controllers\Api\CabinetTelemetryController;
use App\Http\Controllers\Api\GamesSrsOnboardingController;
use App\Http\Controllers\Api\GameTelemetryController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\PublicWaitlistController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AttendanceNoticeController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankClaimController;
use App\Http\Controllers\CabinetMasteryController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CallbackRequestController;
use App\Http\Controllers\CertificateRegistryController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CompanyInvoiceController;
use App\Http\Controllers\DebtPaymentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\DictionaryPageController;
use App\Http\Controllers\DocController;
use App\Http\Controllers\Editor\LectureDraftController;
use App\Http\Controllers\Email\TrackingController as EmailTrackingController;
use App\Http\Controllers\GatedAssetController;
use App\Http\Controllers\GiftCertificateController;
use App\Http\Controllers\GrammarLabController;
use App\Http\Controllers\GrammarLabPilotController;
use App\Http\Controllers\GuestRegisterController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InstituteController;
use App\Http\Controllers\InstituteDonateController;
use App\Http\Controllers\JoinClassController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\MarathonController;
use App\Http\Controllers\MaterialsController;
use App\Http\Controllers\MembershipCommerceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\NewsletterSubscribeController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaypalClaimController;
use App\Http\Controllers\PranaShopController;
use App\Http\Controllers\PranaTransferController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\PublicCabinetGuideController;
use App\Http\Controllers\PublicChatController;
use App\Http\Controllers\PublicPresenceController;
use App\Http\Controllers\PublicWidgetController;
use App\Http\Controllers\ReadingPackController;
use App\Http\Controllers\Rq4StudyController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SrsController;
use App\Http\Controllers\StorefrontAnalyticsController;
use App\Http\Controllers\Student\AccessSelfServiceController;
use App\Http\Controllers\Student\HindiDictionaryDrillsController;
use App\Http\Controllers\Student\HindiMySrsDeckController;
use App\Http\Controllers\Student\HindiProgrammePlaylistController;
use App\Http\Controllers\Student\HindiTgCuratedPracticeController;
use App\Http\Controllers\Student\HindiTranscriptDrillsController;
use App\Http\Controllers\Student\LessonPackController;
use App\Http\Controllers\StudentAgentController;
use App\Http\Controllers\StudentCabinetGuideController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SurveyPageController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TelegramSupportLinkController;
use App\Http\Controllers\TgLoginLinkController;
use App\Http\Controllers\TransliterateController;
use App\Http\Controllers\TrialController;
use App\Http\Controllers\VisualDcsController;
use App\Http\Controllers\VkController;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\LandingPage;
use App\Models\Lesson;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Design\CourseDesignArchiver;
use App\Support\RoleGate;
use App\Support\Roles;
use App\Support\TrajectoryPaths;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Свежий CSRF-токен (анти-419 на чекауте: форма подтягивает токен текущей сессии перед сабмитом)
// throttle:30,1 (H1396 §4) — раньше единственный роут в web-группе БЕЗ троттла, тогда как
// у всех соседних чекаут-роутов он есть. С SESSION_DRIVER=file каждый безкукисный хит
// заставляет StartSession писать НОВЫЙ session-файл — то есть неаутентифицированный
// примитив заполнения диска/inode. 30/мин с запасом хватает легитимному анти-419 потоку
// (один хит на сабмит + обновление из bfcache), спам отсечён.
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))
    ->middleware('throttle:30,1')
    ->name('csrf.token');

// Страница оформления заказа (Checkout)
Route::get('/checkout/{tariff}', [CheckoutController::class, 'show'])->name('checkout.show');

// --- НОВЫЕ РОУТЫ ДЛЯ ПРОМОКОДОВ ---
// throttle на apply — иначе публичный эндпоинт превращается в оракул для
// перебора валидных промокодов (по 10 попыток в минуту хватит легитимному юзеру).
Route::post('/checkout/{tariff}/promo', [CheckoutController::class, 'applyPromo'])
    ->middleware('throttle:10,1')
    ->name('checkout.promo');
Route::post('/checkout/{tariff}/promo/remove', [CheckoutController::class, 'removePromo'])->name('checkout.promo.remove');

// Запрос «разбить оплату на части» (H1290) — уведомляет кураторов, план НЕ создаёт.
// throttle: каждый сабмит — сообщение в кураторский чат; 3 запросов за 10 минут
// хватает легитимному студенту (повторный сабмит после опечатки), спам отсечён.
Route::post('/checkout/{tariff}/installments', [CheckoutController::class, 'requestInstallments'])
    ->middleware('throttle:3,10')
    ->name('checkout.installments');

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

// Фильтры каталога словами в пути, без query string (H3xxx — /online?cat[0]=3
// читался как плохой SEO-слаг). Строгий where() значит порядок регистрации
// относительно /online/kursy/{slug} и т.п. не важен — совпадёт только
// настоящий facet-путь. Парсинг/канонизация: App\Support\ShopCatalogUrl.
Route::get('/online/{facets}', [ShopController::class, 'index'])
    ->where('facets', '(kategoriya|format|uroven|prepodavatel|poisk)(/.+)*')
    ->name('shop.index.facets');

// Клубное членство (H2645): публичный лендинг + прайсинг. Флаг
// features.club_membership проверяется в контроллере (404 до включения):
// страница не может жить раньше, чем контур H2644 реально выдаёт доступ
// за оплату. Цены читаются из тарифов курса `club` (Filament), не из Blade.
Route::get('/klub', [MembershipController::class, 'landing'])->name('membership.landing');
Route::get('/api/public/v1/autumn-membership', [MembershipCommerceController::class, 'feed'])
    ->name('membership.feed.v1');
Route::domain((string) config('membership.public_feed.samskrte_host'))
    ->get('/osen-2026', [MembershipCommerceController::class, 'samskrte'])
    ->name('membership.storefront.samskrte');
Route::domain((string) config('membership.public_feed.samskrtam_host'))
    ->get('/courses/autumn-2026', [MembershipCommerceController::class, 'samskrtam'])
    ->name('membership.storefront.samskrtam');
Route::get('/dvaram/private-archive/{archive}', [MembershipCommerceController::class, 'privateArchive'])
    ->middleware('auth')
    ->where('archive', 'yoga_sutras|soboleva_ayurveda|druzhinin_ayurveda')
    ->name('membership.private-archive');

// «С чего начать» — вводная страница новичка: лесенка продуктов + квиз подбора
// курса + уровни (H323, beginner on-ramp).
Route::get('/online/s-chego-nachat', [ShopController::class, 'start'])->name('shop.start');

// H2764 / R18 — путь через каталог (письмо/чтение → грамматика → тексты).
// Слаг /online/put: столкновений с существующими /online/* нет.
Route::get('/online/put', [ShopController::class, 'pathway'])->name('shop.pathway');

// H3834 — рубрика «Список ожидания» на витрине: голосуй за будущую группу —
// кворум голосов открывает оплату; оплаты к сроку — старт. Регистрируется ДО
// facet-пути /online/{facets} (тот матчит только свои префиксы, но роут
// конкретных слагов — надёжнее выше). Флаг waitlist_voting OFF → 404 в
// контроллере.
Route::get('/online/zhdun', [ShopController::class, 'waitlist'])->name('shop.waitlist');

// Голосование со витрины (H3834 follow-up, 01-09-2026): в api-группе нет
// EnsureFrontendRequestsAreStateful, сессионная кука не подхватывалась и
// контроллер всегда отвечал 401 auth_required даже залогиненному. В web-группе
// сессия + CSRF работают из коробки (токен в разметке страницы); auth-гейт,
// флаг waitlist_voting и троттлинг — в контроллере.
Route::post('/online/zhdun/vote', [PublicWaitlistController::class, 'vote'])
    ->middleware('throttle:10,1')
    ->name('shop.waitlist.vote');

// Отзыв голоса (MG 01-09-2026, «передумал») — та же web-группа: сессия + CSRF.
Route::post('/online/zhdun/unvote', [PublicWaitlistController::class, 'unvote'])
    ->middleware('throttle:10,1')
    ->name('shop.waitlist.unvote');

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
// existing magnet_token (no new token needed). Path uses Sanskrit dine
// (loc. sg. of diná «день»), not English day.
Route::get('/online/konsultaciya/dine/{day}/{token}', [MarathonController::class, 'day'])
    ->where('day', '[12]')->name('marathon.day');
Route::post('/online/konsultaciya/dine/{day}/{token}/complete', [MarathonController::class, 'completeDay'])
    ->where('day', '[12]')->name('marathon.day.complete');
// Legacy English /day/ → permanent redirect (Telegram links already sent).
Route::get('/online/konsultaciya/day/{day}/{token}', function (int $day, string $token) {
    return redirect()->route('marathon.day', ['day' => $day, 'token' => $token], 301);
})->where('day', '[12]');
Route::post('/online/konsultaciya/day/{day}/{token}/complete', function (int $day, string $token) {
    return redirect()->route('marathon.day.complete', ['day' => $day, 'token' => $token], 307);
})->where('day', '[12]');
// H445 Phase 2 — `deva` cohort level-quiz, layered on top of the intent-quiz.
// 404s for `zero` enrollments (MarathonController::levelQuiz()).
Route::get('/online/konsultaciya/level-quiz/{token}', [MarathonController::class, 'levelQuiz'])
    ->name('marathon.level-quiz');
Route::post('/online/konsultaciya/level-quiz/{token}/complete', [MarathonController::class, 'completeLevelQuiz'])
    ->name('marathon.level-quiz.complete');
Route::get('/online/konsultaciya/level-quiz/{token}/result', [MarathonController::class, 'levelQuizResult'])
    ->name('marathon.level-quiz.result');

// H445 Phase 5 — January `deva`-cohort landing (MG ruling 19-08-2026: slug
// `Janvar-27`, launch 13-01-2027). Separate show/register/pay trio, own
// route names — day()/completeDay()/levelQuiz() above stay SHARED (keyed by
// magnet_token, not by which landing created the enrollment).
Route::get('/online/Janvar-27', [MarathonController::class, 'showJanuary'])->name('marathon.january.show');
Route::post('/online/Janvar-27', [MarathonController::class, 'registerJanuary'])->name('marathon.january.register');
Route::post('/online/Janvar-27/pay', [MarathonController::class, 'payJanuary'])->name('marathon.january.pay');

// Страница одного курса (короткий path /k/{slug}; legacy /online/kursy/* → 301 ниже)
Route::get('/k/{course:slug}', [ShopController::class, 'show'])
    ->middleware('course.canonical')
    ->name('shop.course.show');

// H2762 R12 — next-step click logger (Kochergina card only when flag is on).
Route::get('/online/next-step/{target}', [StorefrontAnalyticsController::class, 'nextStep'])
    ->name('shop.next-step');

// Публичный «Пример урока»: отдаёт ТОЛЬКО preview-урок этого курса (is_preview),
// без auth. Никакого lesson-id в URL — гость не может запросить произвольный урок.
Route::get('/k/{course:slug}/preview', [ShopController::class, 'preview'])
    ->middleware('course.canonical')
    ->name('shop.course.preview');

// === ВСТРАИВАЕМЫЙ ВИДЖЕТ РАСПИСАНИЯ (H1427, wave 1b) ===
// Голый HTML-документ без layout/auth; клиентский JS тянет /api/public/schedule.
// frame-ancestors выставляется прямо на ответе (см. PublicWidgetController) — только этот роут.
Route::get('/widgets/schedule', [PublicWidgetController::class, 'schedule'])->name('widgets.schedule');

// Редиректы со старых URL витрины (SEO + старые ссылки/закладки/реклама).
// Имена роутов сохранены, меняются только пути — поэтому route() ниже валиден.
// Специфичный /shop/course/* — ДО общего /shop, иначе общий перехватит.
Route::get('/shop/course/{slug}', function (string $slug) {
    $course = Course::resolveBySlug($slug);
    abort_if($course === null, 404);

    return redirect()->route('shop.course.show', $course->slug, 301);
});
Route::get('/online/kursy/{slug}', function (string $slug) {
    $course = Course::resolveBySlug($slug);
    abort_if($course === null, 404);

    return redirect()->route('shop.course.show', $course->slug, 301);
});
Route::get('/online/kursy/{slug}/preview', function (string $slug) {
    $course = Course::resolveBySlug($slug);
    abort_if($course === null, 404);

    return redirect()->route('shop.course.preview', $course->slug, 301);
});
Route::get('/shop', fn () => redirect()->route('shop.index', [], 301));

// Auth-state probe for the free static games under public/lila/ — lets
// gate.js skip the "register" wall for logged-in students. Public, web-guard
// (session cookie) so it reflects the browser's own login; no CSRF (GET, no
// state change). See public/lila/gate.js.
Route::get('/api/games/auth', fn () => response()->json(['authenticated' => auth()->check()]))
    ->name('games.auth');

// First-party funnel telemetry for the same free games (H1360). Public + web-guard
// so the `authenticated` flag is read server-side from the browser session (the
// client cannot spoof it); anonymous, no PII stored. CSRF-exempt (see
// VerifyCsrfToken) because the beacon carries no token; throttled per IP.
Route::post('/api/games/event', [GameTelemetryController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('games.event');

// H1680 — Wave 2: onboarding-from-games SRS import. Auth-only (it only ever
// reads the CALLER's own game_events, matched by the anon_id they post back);
// throttled like the other games endpoints. No-op while srs.enabled is OFF.
Route::post('/api/games/srs-onboarding-import', [GamesSrsOnboardingController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('games.srs-onboarding-import');

// Public deck trial (shareable per-deck URLs, no auth). Canonical path: /koloda.
// Must sit BEFORE the promo catch-all /{slug}. Same srs.enabled gate as cabinet.
if (config('srs.enabled')) {
    Route::get('/koloda', [SrsController::class, 'publicIndex'])->name('srs.index');

    // Язык словом в пути вместо ?lang= (H3093-паттерн). ДО /koloda/{slug} —
    // иначе implicit slug съел бы /koloda/yazyk как имя колоды.
    Route::get('/koloda/yazyk/{word}', [SrsController::class, 'publicIndex'])
        ->where('word', 'sanskrit|hindi|vse')
        ->name('srs.index.lang');

    Route::get('/koloda/{slug}', [SrsController::class, 'publicReview'])->name('srs.deck');

    // Legacy /srs → /koloda (301). Keep old Telegram/blog links working.
    Route::permanentRedirect('/srs', '/koloda');
    Route::get('/srs/{slug}', function (string $slug) {
        return redirect('/koloda/'.$slug, 301);
    })->where('slug', '[A-Za-z0-9\-]+');
}

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
    // H2317 — предварительное предупреждение о занятии (не приду / не уверен /
    // опоздаю / уйду раньше). Контроллер 404 при features.attendance_notices OFF.
    Route::post('/calendar/{schedule}/notice', [AttendanceNoticeController::class, 'store'])
        ->whereNumber('schedule')
        ->name('student.calendar.notice.store');
    Route::delete('/calendar/{schedule}/notice', [AttendanceNoticeController::class, 'destroy'])
        ->whereNumber('schedule')
        ->name('student.calendar.notice.destroy');
    Route::get('/dvaram', [StudentController::class, 'dashboard'])->name('student.dashboard');

    Route::get('/dvaram/proverka', [CabinetMasteryController::class, 'show'])
        ->name('student.cabinet-mastery');
    Route::post('/dvaram/proverka', [CabinetMasteryController::class, 'submit'])
        ->name('student.cabinet-mastery.submit');

    Route::get('/dvaram/help', [StudentCabinetGuideController::class, 'show'])
        ->name('student.help');

    // Bounded student agent (H3231): homework hint / dictionary lookup /
    // cabinet FAQ only, no free chat. 404 while features.student_agent OFF.
    Route::post('/dvaram/agent', [StudentAgentController::class, 'run'])
        ->name('student.agent.run');

    Route::get('/open-lessons', [StudentController::class, 'openLessons'])->name('student.open-lessons');

    // Колоды / интервальные повторения (H211) — только при srs.enabled.
    // Canonical URL: /dvaram/koloda (legacy /dvaram/srs → 301).
    // Static segments (stats/decks) MUST be registered before {slug}.
    if (config('srs.enabled')) {
        Route::get('/dvaram/koloda/stats', [SrsController::class, 'stats'])
            ->name('student.srs.stats');

        Route::get('/dvaram/koloda/decks', [SrsController::class, 'decks'])
            ->name('student.srs.decks');

        Route::get('/dvaram/koloda/{slug}', [SrsController::class, 'review'])
            ->name('student.srs.deck');

        Route::get('/dvaram/koloda', [SrsController::class, 'cabinetIndex'])
            ->name('student.srs');

        // Legacy /dvaram/srs → /dvaram/koloda
        Route::permanentRedirect('/dvaram/srs', '/dvaram/koloda');
        Route::permanentRedirect('/dvaram/srs/stats', '/dvaram/koloda/stats');
        Route::permanentRedirect('/dvaram/srs/decks', '/dvaram/koloda/decks');
        Route::get('/dvaram/srs/{slug}', function (string $slug) {
            return redirect('/dvaram/koloda/'.$slug, 301);
        })->where('slug', '[A-Za-z0-9\-]+');
    }

    // H2110 — Wave 2: «Старт чтения» cohort reading packs INSIDE the cabinet.
    // Registered unconditionally (unlike the SRS block above) because the gate is
    // per-request entitlement, not a boot-time flag: ReadingPackController checks
    // features.kosha_reader AND StartChteniyaCohort::hasEntitlement($user), so a
    // logged-in student who has not bought the cohort gets 404, not a redirect.
    // Static segment before {slug}, same ordering discipline as /dvaram/koloda.
    // H2441 — Hindi programme playlist. Controller 404s while the flag is OFF.
    // Classic cabinet only: no hybrid /library intermediate.
    Route::get('/dvaram/programme/hindi', [HindiProgrammePlaylistController::class, 'hindi'])
        ->name('student.programme.hindi');

    // H2445 — «в колоду» from the playlist row. Item text is read from the
    // transcript extractor server-side. Not behind srs.enabled (H2106 fence).
    Route::post('/dvaram/programme/hindi/srs', [HindiMySrsDeckController::class, 'addLesson'])
        ->name('student.programme.hindi.srs');

    // H2446 — curated TG practice (JSON store, no live Telegram). Flag OFF → 404.
    Route::get('/dvaram/programme/hindi/chat-practice', [HindiTgCuratedPracticeController::class, 'show'])
        ->name('student.programme.hindi.tg');
    Route::post('/dvaram/programme/hindi/chat-practice/check', [HindiTgCuratedPracticeController::class, 'check'])
        ->name('student.programme.hindi.tg.check');

    // H3206 — Kostina module dictionaries. Static segment before {module}.
    Route::get('/dvaram/programme/hindi/vocab', [HindiDictionaryDrillsController::class, 'index'])
        ->name('student.programme.hindi.vocab');
    Route::get('/dvaram/programme/hindi/vocab/{module}', [HindiDictionaryDrillsController::class, 'show'])
        ->name('student.programme.hindi.vocab.show')
        ->where('module', 'M(?:[1-9]|1[0-2])');
    Route::post('/dvaram/programme/hindi/vocab/{module}/check', [HindiDictionaryDrillsController::class, 'check'])
        ->name('student.programme.hindi.vocab.check')
        ->where('module', 'M(?:[1-9]|1[0-2])');

    Route::get('/dvaram/reading', [ReadingPackController::class, 'cabinetIndex'])
        ->name('student.reading.index');

    // H2168 — per-COURSE reading packs (Nalopākhyāna 3 packs, Subhāṣita 1).
    // Registered BEFORE /dvaram/reading/{slug} on purpose: `kurs` would otherwise
    // match that wildcard, same static-segment-first discipline as /dvaram/koloda.
    // Gate is CourseCohortEntitlement::hasEntitlement($user, $course) — the course's
    // own `cohort_courses.<slug>.enabled` switch (default OFF) plus a real paid
    // payment for THAT course, so it is prod-inert until ops enables it and a
    // payment on one course never opens the other's reader.
    Route::get('/dvaram/reading/kurs/{course}', [ReadingPackController::class, 'courseIndex'])
        ->name('student.reading.course.index')
        ->where('course', '[a-z0-9_\-]+');

    Route::get('/dvaram/reading/kurs/{course}/{slug}', [ReadingPackController::class, 'coursePack'])
        ->name('student.reading.course.pack')
        ->where('course', '[a-z0-9_\-]+')
        ->where('slug', '[a-z0-9\-]+');

    Route::get('/dvaram/reading/{slug}', [ReadingPackController::class, 'cabinetShow'])
        ->name('student.reading.pack')
        ->where('slug', '[a-z0-9\-]+');

    // H2111 — «в колоду» from the tap-token panel. Same gate as the reader (flag +
    // entitlement, checked per request inside the controller), deliberately NOT behind
    // config('srs.enabled'): collecting a card must not require flipping the global SRS
    // switch (H2106 fence).
    Route::post('/dvaram/reading/{slug}/srs', [ReadingPackController::class, 'addToSrs'])
        ->name('student.reading.srs.add')
        ->where('slug', '[a-z0-9\-]+');

    // H2107 — fire-and-forget token-lookup telemetry (same gate, positions only).
    // Feeds student progress % and the teacher stalled-lemma view — see
    // App\Services\StartChteniyaProgress.
    Route::post('/dvaram/reading/{slug}/lookup', [ReadingPackController::class, 'logLookup'])
        ->name('student.reading.lookup')
        ->where('slug', '[a-z0-9\-]+');

    // H1680 — Wave 2: cabinet skill-drill strip, DISTINCT from the FSRS
    // review loop above (/dvaram/koloda) — short /lila drills linked from the
    // cabinet, no spaced-repetition scheduling here. Behind its own flag
    // (default OFF, Architecture §5: "any cabinet/SRS surfacing remains
    // OFF by default"), independent of srs.enabled.
    if (config('features.games_skill_drills')) {
        Route::get('/dvaram/skill-drills', [StudentController::class, 'skillDrills'])
            ->name('student.skill-drills');
    }

    // H2482 — native VisualDCS surfaces. Flags checked per request so one
    // surface can 404 without unregistering the other two.
    // H2493 — Grammar Lab explorer. Controller 404s when features.grammar_lab
    // is OFF and 403s (empty payload) when the user is not entitled.
    Route::get('/dvaram/grammar-lab', [GrammarLabController::class, 'index'])
        ->name('student.grammar-lab.index');
    Route::get('/dvaram/grammar-lab/search', [GrammarLabController::class, 'search'])
        ->name('student.grammar-lab.search');
    Route::get('/dvaram/grammar-lab/history', [GrammarLabController::class, 'history'])
        ->name('student.grammar-lab.history');
    Route::get('/dvaram/grammar-lab/t/{slug}', [GrammarLabController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.show');
    Route::get('/dvaram/grammar-lab/t/{slug}/compare', [GrammarLabController::class, 'compare'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.compare');
    Route::post('/dvaram/grammar-lab/t/{slug}/bookmark', [GrammarLabController::class, 'bookmark'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.bookmark');
    Route::get('/dvaram/grammar-lab/t/{slug}/practice', [GrammarLabController::class, 'practice'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.practice');
    Route::post('/dvaram/grammar-lab/t/{slug}/practice', [GrammarLabController::class, 'attempt'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.attempt');
    Route::post('/dvaram/grammar-lab/t/{slug}/srs', [GrammarLabController::class, 'addToSrs'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.srs');
    Route::get('/dvaram/grammar-lab/pilot', [GrammarLabPilotController::class, 'intro'])
        ->name('student.grammar-lab.pilot');
    Route::post('/dvaram/grammar-lab/pilot', [GrammarLabPilotController::class, 'enroll'])
        ->name('student.grammar-lab.pilot.enroll');
    Route::post('/dvaram/grammar-lab/pilot/confusion', [GrammarLabPilotController::class, 'confusion'])
        ->name('student.grammar-lab.pilot.confusion');

    Route::get('/dvaram/visualdcs', [VisualDcsController::class, 'hub'])
        ->name('student.visualdcs.hub');
    Route::get('/dvaram/visualdcs/{surface}', [VisualDcsController::class, 'index'])
        ->where('surface', 'verb|nominal|passage')
        ->name('student.visualdcs.index');
    Route::get('/dvaram/visualdcs/{surface}/{id}', [VisualDcsController::class, 'show'])
        ->where('surface', 'verb|nominal|passage')
        ->where('id', '.+')
        ->name('student.visualdcs.show');
    Route::post('/dvaram/visualdcs/{surface}/{id}/progress', [VisualDcsController::class, 'storeProgress'])
        ->where('surface', 'verb|nominal|passage')
        ->where('id', '.+')
        ->name('student.visualdcs.progress');

    // H987 — RQ4 user study (on-ramp-first vs Талмуд-first). За фича-флагом
    // features.rq4_study, ВЫКЛ по умолчанию (404 пока не включен).
    Route::prefix('rq4-study')->name('rq4.')->group(function () {
        Route::get('/', [Rq4StudyController::class, 'intro'])->name('intro');
        Route::post('/enroll', [Rq4StudyController::class, 'enroll'])->name('enroll');
        Route::get('/start-arm', [Rq4StudyController::class, 'startArm'])->name('start-arm');
        Route::get('/diagnostic/{phase}', [Rq4StudyController::class, 'diagnostic'])->name('diagnostic');
        Route::post('/diagnostic/{phase}', [Rq4StudyController::class, 'submitAnswer'])->name('diagnostic.submit');
    });

    Route::get('/messages', [StudentController::class, 'messages'])->name('student.messages');

    // H2747 — Phase 0/1 consented callback request (H2486 packet §4.3/§8).
    // Controller 404s while features.telephony_callback_request is OFF.
    // No provider HTTP, no PSTN — writes FollowUpTask + CallEvent::requested only.
    Route::get('/support/callback', [CallbackRequestController::class, 'show'])
        ->name('student.support.callback');
    Route::post('/support/callback', [CallbackRequestController::class, 'store'])
        ->name('student.support.callback.store');

    // H1481 hybrid chassis (R29 Phase 1): job-named pages. Controllers 404 when
    // features.cabinet_hybrid is OFF so prod is unchanged until the flag flips.
    Route::get('/library', [StudentController::class, 'library'])->name('student.library');

    // Короткая help-страница «Почему баланс праны уменьшился?» (H1756) —
    // закрывает частый вопрос поддержки про сгорание/списания праны.
    Route::view('/help/prana-balance', 'help.prana-balance')->name('help.prana-balance');

    // Старый URL → канон /faq/dz (публичный FAQ; в кабинете «как сдавать» тоже туда).
    Route::redirect('/help/homework', '/faq/dz', 301)->name('help.homework');
    Route::get('/progress', [StudentController::class, 'progress'])->name('student.progress');
    Route::get('/access', [StudentController::class, 'access'])->name('student.access');

    // Клубное членство (H2644): самостоятельный отказ от продления и возврат.
    // Флаг features.membership_cancellation проверяется в контроллере (404).
    Route::post('/membership/cancel', [MembershipController::class, 'cancel'])
        ->name('student.membership.cancel');
    Route::post('/membership/resume', [MembershipController::class, 'resume'])
        ->name('student.membership.resume');

    // Короткие URL кабинета: /c/{slug}, /c/{slug}/u/{lessonId} (урок).
    // Legacy /course/... → 301 (см. блок ниже, внутри auth).
    Route::get('/c/{slug}', [StudentController::class, 'showCourse'])
        ->middleware('course.canonical')
        ->name('student.course');
    Route::post('/c/{slug}/access/materialize', [AccessSelfServiceController::class, 'materialize'])
        ->middleware('course.canonical')
        ->name('student.access.materialize');
    Route::get('/c/{slug}/u/{lessonId}', [StudentController::class, 'showLesson'])
        ->middleware('course.canonical')
        ->name('student.lesson');
    Route::get('/c/{slug}/u/{lessonId}/drills', [HindiTranscriptDrillsController::class, 'show'])
        ->middleware('course.canonical')
        ->name('student.lesson.drills');
    Route::post('/c/{slug}/u/{lessonId}/drills/check', [HindiTranscriptDrillsController::class, 'check'])
        ->middleware('course.canonical')
        ->name('student.lesson.drills.check');
    Route::post('/c/{slug}/u/{lessonId}/srs', [HindiMySrsDeckController::class, 'addItem'])
        ->middleware('course.canonical')
        ->name('student.lesson.srs.add');

    // H3521: Learn Your Way — персонализированный пак занятия. Default-OFF
    // (LYW_ENABLED): флаг выключен => 404 и вкладка на уроке не рендерится.
    Route::get('/c/{slug}/u/{lessonId}/learn', [LessonPackController::class, 'show'])
        ->middleware('course.canonical')
        ->name('student.lesson.lessonpack');

    Route::post('/c/{slug}/u/{lessonId}/complete', [StudentController::class, 'completeLesson'])
        ->name('student.lesson.complete');

    // H3308: гейт-выдача контента урока, снятого с публичного диска —
    // стенограмма, материалы, справочные файлы ДЗ. Гейт тот же, что у плеера.
    Route::get('/c/{slug}/u/{lessonId}/transcript', [GatedAssetController::class, 'transcript'])
        ->whereNumber('lessonId')
        ->name('student.lesson.transcript');
    Route::get('/c/{slug}/u/{lessonId}/materials/{file}', [GatedAssetController::class, 'material'])
        ->whereNumber('lessonId')->where('file', '[A-Za-z0-9._-]+')
        ->name('student.lesson.material');
    Route::get('/c/{slug}/u/{lessonId}/homework-files/{file}', [GatedAssetController::class, 'homeworkRef'])
        ->whereNumber('lessonId')->where('file', '[A-Za-z0-9._-]+')
        ->name('student.lesson.homework-file');

    Route::get('/c/{slug}/materials/download', [StudentController::class, 'downloadCourseMaterials'])
        ->middleware('course.canonical')
        ->name('student.course.materials.download');

    // Библиотека курса — реестр ссылок на литературу (файлы лежат не у нас).
    // За флагом features.course_library; выключен — 404.
    Route::get('/c/{slug}/library', [StudentController::class, 'courseLibrary'])
        ->middleware('course.canonical')
        ->name('student.course.library');

    Route::post('/c/{slug}/u/{lessonId}/note', [StudentController::class, 'saveNote'])
        ->name('student.lesson.note');

    // Домашние задания: сдача студентом + контролируемое скачивание файлов
    Route::post('/c/{slug}/u/{lessonId}/homework', [HomeworkController::class, 'store'])
        ->name('student.homework.store');

    // Legacy path 301 → short /c/... (только GET; POST остаются на новых action).
    Route::get('/course/{slug}', function (string $slug) {
        $course = Course::resolveBySlug($slug);
        abort_if($course === null, 404);

        return redirect()->route('student.course', $course->slug, 301);
    });
    Route::get('/course/{slug}/lesson/{lessonId}', function (string $slug, $lessonId) {
        $course = Course::resolveBySlug($slug);
        abort_if($course === null, 404);

        return redirect()->route('student.lesson', [$course->slug, $lessonId], 301);
    });
    Route::get('/homework/file/{file}', [HomeworkController::class, 'download'])
        ->name('homework.file.download');
    Route::get('/homework/submission/{submission}/images-pdf', [HomeworkController::class, 'downloadImagesPdf'])
        ->name('homework.submission.images-pdf');
    Route::delete('/homework/file/{file}', [HomeworkController::class, 'destroyFile'])
        ->name('homework.file.destroy');
    Route::post('/homework/file/{file}/move', [HomeworkController::class, 'moveFile'])
        ->name('homework.file.move');
    Route::delete('/homework/comment/{comment}', [HomeworkController::class, 'destroyComment'])
        ->name('homework.comment.destroy');

    Route::post('/api/heartbeat', [HeartbeatController::class, 'store'])
        ->name('activity.heartbeat');

    // Baseline-телеметрия ремейка кабинета (H962, спека §4): клиентские клики/
    // импрешены из первопартийного JS (никаких сторонних трекеров, R20).
    Route::post('/dvaram/telemetry', [CabinetTelemetryController::class, 'store'])
        ->name('student.telemetry');

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

    // H3313: GET — инструкция, выдача токена только CSRF-защищённым POST.
    Route::get('/telegram/connect', [TelegramController::class, 'connect'])->name('telegram.connect');
    Route::post('/telegram/connect', [TelegramController::class, 'start'])->name('telegram.connect.start');

    // Привязка VK через одноразовый токен (вместо сырого ?ref={user_id}) — см. VkController.
    Route::get('/vk/connect', [VkController::class, 'connect'])->name('vk.connect');
    Route::post('/vk/connect', [VkController::class, 'start'])->name('vk.connect.start');

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

// --- ОТПРАВКА ФОРМЫ ---
Route::post('/leads/store', [LeadController::class, 'store'])->name('leads.store');

// Один клик от вошедшего ученика: поля заполняются из кабинета, форма их не показывает.
Route::post('/leads/one-click', [LeadController::class, 'oneClick'])
    ->middleware('auth')
    ->name('leads.one-click');

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

// --- СВЯЗЫВАНИЕ TELEGRAM С КАБИНЕТОМ (H3542) — по capability-ссылке из
// приглашения саппорт-бота в DM. Самогейтится флагом support_dm_link_invite
// (404 при OFF). Строго до catch-all /{slug}; публичные; троттлинг в контроллере.
Route::get('/support/link/{token}', [TelegramSupportLinkController::class, 'show'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('support.telegram.link');
Route::post('/support/link/{token}', [TelegramSupportLinkController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('support.telegram.link.submit');

// --- ТРЕКИНГ РАССЫЛОК (H1449 B4) — оба самогейтятся по email_campaigns (404 при OFF).
// Токен резолвит CampaignRecipient на сервере — PII в URL никогда не попадает.
Route::get('/e/o/{token}.gif', [EmailTrackingController::class, 'openPixel'])
    ->where('token', '[A-Za-z0-9\-]+')
    ->name('email.track.open');
Route::get('/e/c/{token}/{link}', [EmailTrackingController::class, 'click'])
    ->where(['token' => '[A-Za-z0-9\-]+', 'link' => '[A-Za-z0-9\-_]+'])
    ->name('email.track.click');
// --- ССЫЛКА ВХОДА ПОСЛЕ РАЗБЛОКИРОВКИ (H849) — админ выдаёт студенту, минуя
// сломанную почту. НЕ завязано на newsletter-флаг; принимает только токены
// назначения admin_unblock (см. StudentUnblockService::MAGIC_PURPOSE).
Route::get('/login-link/{token}', [AdminLoginLinkController::class, 'login'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('admin.login-link');

// --- «TELEGRAM-ВХОД» (CABINET_ADOPTION_ROADMAP P2) — одноразовая ссылка,
// выданная студент-ботом в чат (владение Telegram = фактор подлинности).
// Самогейтится флагом telegram_cabinet_login (404 при OFF); принимает только
// токены назначения tg_login (см. TelegramLoginService::MAGIC_PURPOSE).
Route::get('/tg-login/{token}', [TgLoginLinkController::class, 'login'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('tg.login-link');

// --- РЕЖИМ ПРОСМОТРА ЗА ПОЛЬЗОВАТЕЛЯ (H1947) ---
// Старт — подписанная короткоживущая ссылка из UserResource (подпись закрывает
// CSRF на GET; права всё равно перепроверяет контроллер). Выход — POST из
// плашки. При выключенном features.staff_impersonation оба отдают 404.
Route::middleware(['auth', 'signed'])
    ->get('/impersonate/{user}/{mode}', [ImpersonationController::class, 'start'])
    ->where('mode', 'student|manager|teacher')
    ->name('impersonate.start');
Route::middleware('auth')
    ->post('/impersonate/stop', [ImpersonationController::class, 'stop'])
    ->name('impersonate.stop');
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
// H3990: режим доплаты (разовая акция, без нового тарифа) — та же форма с
// фиксированной €22/$26 и проводкой «закрыть открытый счёт-доплату 2 000 ₽».
Route::get('/paypal/{tariff}/doplata', [PaypalClaimController::class, 'showSupplement'])
    ->name('paypal.claim.supplement');
Route::post('/paypal/{tariff}', [PaypalClaimController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('paypal.claim.store');

// Оплата банковским переводом (SEPA/SWIFT на внешний счёт получателя школы,
// H3497): зеркало PayPal-заявки. Флаг BANK_CLAIM_ENABLED default OFF (404).
// Строго до catch-all /{slug}; throttle:5,1 — защита от спама pending-платежей.
Route::get('/bank/{tariff}', [BankClaimController::class, 'show'])
    ->name('bank.claim.show');
Route::post('/bank/{tariff}', [BankClaimController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('bank.claim.store');

// Счёт для компании / ИП (безнал). Flag COMPANY_INVOICE_ENABLED; pending until
// admin confirms bank transfer. Print path BEFORE /invoice/{tariff} so "print"
// is never resolved as a Tariff id. Strictly before catch-all /{slug}.
Route::get('/invoices/{payment}/print', [CompanyInvoiceController::class, 'print'])
    ->middleware('auth')
    ->name('invoice.print');
Route::get('/invoice/{tariff}', [CompanyInvoiceController::class, 'show'])
    ->name('invoice.claim.show');
Route::post('/invoice/{tariff}', [CompanyInvoiceController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('invoice.claim.store');

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

// H445 Phase 4 (H546) — Day-2 mantra-reading voice note (marathon).
// Диск 'local' (не public) — приватная запись голоса, не публичный медиафайл.
Route::get('/admin/marathon/mantra-voice/{enrollment}', function (MarathonEnrollment $enrollment) {
    abort_unless(RoleGate::adminOnly(), 403);
    abort_unless(
        filled($enrollment->day2_voice_path) && Storage::disk((string) $enrollment->day2_voice_disk)->exists($enrollment->day2_voice_path),
        404
    );

    return Storage::disk((string) $enrollment->day2_voice_disk)->download($enrollment->day2_voice_path);
})->middleware('auth')->name('admin.marathon.mantra-voice.download');

// Исходник PSD дизайнерского баннера: приватный диск, отдаём только персоналу.
// Свой маршрут, а НЕ существующий /force-download: тот пускает по legacy-флагу
// is_admin (синхронен с super_admin/admin), и manager — для которого страница
// «Дизайн курсов» и делалась — через него не прошёл бы.
Route::get('/admin/course-design/{asset}/psd', function (CourseDesignAsset $asset) {
    abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);
    abort_unless(
        filled($asset->psd_path) && Storage::disk((string) $asset->psd_disk)->exists($asset->psd_path),
        404
    );

    return Storage::disk((string) $asset->psd_disk)
        ->download($asset->psd_path, $asset->psd_original_name ?: basename($asset->psd_path));
})->middleware('auth')->name('course-design.psd');

// ZIP с баннерами курса: маршрут сам собирает архив и тут же его отдаёт, удаляя
// файл после отправки. Собирать в Filament-действии и редиректить сюда нельзя —
// Filament оставляет после такого редиректа пустую модалку действия (поймано
// прокликиванием). Заодно в пути нет ни токена, ни имени файла от пользователя.
Route::get('/admin/course-design/{course}/archive', function (Course $course, CourseDesignArchiver $archiver) {
    abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);

    try {
        $path = $archiver->build($course);
    } catch (RuntimeException $e) {
        abort(404, $e->getMessage());
    }

    return response()->download($path, $archiver->downloadName($course))->deleteFileAfterSend();
})->middleware('auth')->name('course-design.archive');

// Скачивание планировочных шаблонов «Нескучных финансов» (Финмодель, Бюджет,
// План доходов/расходов) — гибридная стратегия H207: живые отчёты в панели +
// эти workbooks вручную. Доступ — админ ИЛИ бухгалтер (+ супер-админ).
// Имена берём из белого списка, чтобы исключить обход каталога.
// H3214 — кадры книги бухгалтера из storage (не git). Basename only.
Route::get('/staff/accountant-guide-shots/{file}', [AccountantGuideShotController::class, 'show'])
    ->middleware('auth')
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('accountant-guide.shot');

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

// Generated /llms.txt (SAMSKRTE-SEO-H2 W3). Not a static dump.
Route::get('/llms.txt', [LlmsTxtController::class, 'index'])->name('llms.txt');

// --- ВЕРИФИКАЦИЯ СЕРТИФИКАТА (ссылка из QR-кода) ---
// ВАЖНО: до catch-all /{slug}, публичный без auth.
Route::get('/verify/{number}', [CertificateVerificationController::class, 'show'])
    ->name('certificate.verify');

// --- ПУБЛИЧНЫЙ РЕЕСТР ВЫДАННЫХ СЕРТИФИКАТОВ/СПРАВОК ---
// ВАЖНО: до catch-all /{slug}, публичный без auth. Полные ФИО — решение MG.
Route::get('/sertifikat', [CertificateRegistryController::class, 'index'])
    ->name('certificate.registry');

// Старый адрес /sertifikaty остаётся живым 301-редиректом: он уже попал в sitemap.
Route::get('/sertifikaty', function () {
    $query = request()->getQueryString();

    return redirect('/sertifikat'.($query ? '?'.$query : ''), 301);
});

// --- ПОДАРОЧНЫЕ СЕРТИФИКАТЫ (H3334) ---
// ВАЖНО: до catch-all /{slug}. Каждая поверхность самогейтится флагом
// features.gift_certificates (404 при OFF — см. GiftCertificateController).
// Активация — только для залогиненных (доступ открывается конкретному юзеру);
// POST троттлится против перебора кодов; верификация публична, как /verify/{number}.
Route::get('/gift/activate', [GiftCertificateController::class, 'showActivate'])
    ->name('gift.activate');
Route::post('/gift/activate', [GiftCertificateController::class, 'activate'])
    ->middleware('throttle:10,1')
    ->name('gift.activate.attempt');
Route::get('/gift/verify/{number}', [GiftCertificateController::class, 'verify'])
    ->name('gift.verify');

// --- ПУБЛИЧНЫЕ АНКЕТЫ (движок опросов; рулинг MG 24-08-2026 — вариант Б) ---
// ВАЖНО: до catch-all /{slug}. Самогейтится флагом SURVEYS_ENABLED (404 при OFF).
// POST троттлится против спама + ханипот в форме (SurveyPageController@store).
Route::get('/anketa/{slug}', [SurveyPageController::class, 'show'])
    ->name('survey.show');
Route::post('/anketa/{slug}', [SurveyPageController::class, 'store'])
    ->middleware('throttle:20,60')
    ->name('survey.store');

// Выгрузка ответов CSV для куратора (админ/менеджер).
Route::get('/admin/surveys/{slug}/export', [SurveyPageController::class, 'exportCsv'])
    ->middleware('throttle:30,60')
    ->name('survey.export');
Route::get('/gift/{certificate}/download', [GiftCertificateController::class, 'download'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('gift.download');

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

// === ЖИВОЙ ВЕБ-ЧАТ ПОДДЕРЖКИ (H536) ===
// Публичный, rate-limited: гость/студент открывает тред и шлёт сообщение без
// перезагрузки; бродкаст оператору через Reverb (Phase 3). Строго до catch-all.
Route::post('/chat/message', [PublicChatController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('chat.message');
Route::get('/chat/history', [PublicChatController::class, 'history'])
    ->middleware('throttle:60,1')
    ->name('chat.history');

// Presence-beacon проактивного монитора посетителей (H1197, Jivo-паритет Pillar 2).
// Публичный, rate-limited; за флагом support_visitor_presence (иначе no-op). Виджет
// стучит сюда с интервалом support_presence.beacon_interval_seconds. Строго до catch-all.
Route::post('/support/presence', [PublicPresenceController::class, 'ping'])
    ->middleware('throttle:20,1')
    ->name('support.presence');

Route::get('/{slug}', [PromoController::class, 'show'])->name('promo.show');
