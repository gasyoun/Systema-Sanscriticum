<?php

// H4516 — shop domain (split from routes/web.php; lines 108-324).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\Api\PublicWaitlistController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MarathonController;
use App\Http\Controllers\MaterialsController;
use App\Http\Controllers\MembershipCommerceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\PublicSchedulePageController;
use App\Http\Controllers\PublicWidgetController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StorefrontAnalyticsController;
use App\Http\Controllers\SubscriptionLandingController;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lesson;
use App\Models\Testimonial;
use App\Support\TrajectoryPaths;
use Illuminate\Support\Facades\Route;

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

// Подписка «в записи» (H3916): публичный лендинг годовой подписки на архив
// факультативов. Флаг features.recorded_subscription проверяется в контроллере
// (404 до включения) — по тому же правилу, что и /klub.
Route::get('/podpiska-zapisi', SubscriptionLandingController::class)
    ->name('subscription.landing');
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

// === ПУБЛИЧНАЯ СТРАНИЦА «РАСПИСАНИЕ» (H4340) ===
// Все расписания всех курсов тем же билдером, что и Telegram-пост (H4328).
// Виджет выше остаётся встраиваемой поверхностью samskrtam.ru/raspisanie;
// эта страница — человеческий эквивалент на samskrte.ru. Без auth.
Route::get('/raspisanie', PublicSchedulePageController::class)
    ->name('schedule.page');

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
