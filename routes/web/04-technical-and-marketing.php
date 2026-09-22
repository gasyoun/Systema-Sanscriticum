<?php

use App\Filament\Resources\UserResource;
use App\Http\Controllers\AdminLoginLinkController;
use App\Http\Controllers\CabinetInviteLinkController;
use App\Http\Controllers\CourseInterestController;
use App\Http\Controllers\Email\TrackingController as EmailTrackingController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NewsletterSubscribeController;
use App\Http\Controllers\TelegramSupportLinkController;
use App\Http\Controllers\TgLoginLinkController;
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

// --- ЗАЯВКА ИНТЕРЕСА НА КУРС (H5066) — join / recording / revive.
// Самогейтится флагом course_interest_form (404 при OFF). Строго до catch-all
// /{slug}; публичные; анти-спам (honeypot + time-trap + rate-limit) в контроллере.
// /interest/{slug}/embed — минимальный iframe-вариант для samskrtam.ru.
Route::get('/interest/{course?}', [CourseInterestController::class, 'show'])
    ->name('course-interest.show');
Route::get('/interest/{course?}/embed', [CourseInterestController::class, 'embed'])
    ->name('course-interest.embed');
Route::post('/interest/{course?}', [CourseInterestController::class, 'store'])
    ->name('course-interest.store');

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

// --- ПРИГЛАШЕНИЕ В КАБИНЕТ (H4966) — многодневная ссылка от
// SendCabinetInvites, заменяет 60-минутную ссылку сброса пароля. Принимает
// только токены назначения cabinet_invite.
Route::get('/cabinet-invite/{token}', [CabinetInviteLinkController::class, 'login'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('cabinet.invite');

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
