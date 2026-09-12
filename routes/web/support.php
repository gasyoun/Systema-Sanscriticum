<?php

// H4516 — support domain (split from routes/web.php; lines 953-1026).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\AdminLoginLinkController;
use App\Http\Controllers\Email\TrackingController as EmailTrackingController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NewsletterSubscribeController;
use App\Http\Controllers\TelegramSupportLinkController;
use App\Http\Controllers\TgLoginLinkController;
use Illuminate\Support\Facades\Route;

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
