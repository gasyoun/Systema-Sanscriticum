<?php

// H4516 — public domain (split from routes/web.php; lines 1183-1294).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
// The promo catch-all /{slug} at the bottom MUST stay the LAST
// registered web route — every file above relies on it.

use App\Filament\Resources\UserResource;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CertificateRegistryController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\GiftCertificateController;
use App\Http\Controllers\JoinClassController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\PublicChatController;
use App\Http\Controllers\PublicPresenceController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SurveyPageController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
