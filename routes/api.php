<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CabinetController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\PartnerBotController;
use App\Http\Controllers\Api\PublicScheduleController;
use App\Http\Controllers\Api\PublicTrialBookController;
use App\Http\Controllers\Api\VkBotController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Webhooks\ExamScoresWebhookController;
use App\Http\Controllers\Webhooks\LeadStepWebhookController;
use App\Http\Controllers\Webhooks\LectureClipCallbackWebhookController;
use App\Http\Controllers\Webhooks\MaxMagnetWebhookController;
use App\Http\Controllers\Webhooks\PaypalSubscriptionsWebhookController;
use App\Http\Controllers\Webhooks\TelegramMagnetWebhookController;
use App\Http\Controllers\Webhooks\TelegramZapisiWebhookController;
use App\Http\Controllers\Webhooks\VkMagnetCallbackController;
use App\Http\Controllers\Webhooks\ZoomWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// === ПУБЛИЧНЫЙ ФИД РАСПИСАНИЯ (H1427, wave 1b) ===
// Без аутентификации, для встраиваемого виджета samskrtam.ru/raspisanie.
// Строгий allowlist полей в PublicScheduleResource; троттлинг 30/мин; кэш 5 мин.
Route::get('/public/schedule', [PublicScheduleController::class, 'index'])
    ->middleware('throttle:30,1')
    ->name('api.public.schedule');

// H3248: публичная запись на бесплатное пробное занятие из виджета. Требует ОБА
// флага (crm_trial_widget_public + crm_trial_booking), иначе контроллер 404.
// В ответе никогда нет ссылки Zoom или внутренних id. Троттлинг 5/мин.
Route::post('/public/schedule/book', PublicTrialBookController::class)
    ->middleware('throttle:5,1')
    ->name('api.public.schedule.book');

// === МОБИЛЬНОЕ ПРИЛОЖЕНИЕ (Sanctum personal access tokens) ===
Route::prefix('v1')->group(function () {
    // Публичный логин (выдаёт токен). Троттлим — публичный приём пароля.
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('/courses', [CabinetController::class, 'courses'])->name('api.courses');
        Route::get('/courses/{slug}/lessons', [CabinetController::class, 'lessons'])->name('api.courses.lessons');
    });
});

Route::post('/sync-lessons', [LessonController::class, 'sync']);
Route::post('/lessons/from-zoom', [LessonController::class, 'storeFromZoom']);
// Расшифровка Deepgram из того же сценария: без неё нарезка клипов пуста.
Route::post('/lessons/{lesson}/transcript', [LessonController::class, 'storeTranscript'])
    ->name('api.lessons.transcript');

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('verify.tg.bot');

Route::post('/vk-webhook', [VkBotController::class, 'handle'])
    ->middleware('verify.vk.bot');

Route::post('/webhooks/tochka', [WebhookController::class, 'handleTochkaWebhook']);

// H2027 PayPal Subscriptions — dark flag (404 when PAYPAL_SUBSCRIPTIONS_ENABLED=false).
Route::post('/webhooks/paypal-subscriptions', PaypalSubscriptionsWebhookController::class)
    ->name('webhook.paypal.subscriptions');

// Zoom Event Subscription: запись вебинара готова (recording.completed) +
// проверка URL. Подпись (x-zm-signature) проверяется внутри контроллера.
Route::post('/webhooks/zoom', [ZoomWebhookController::class, 'handle'])
    ->name('webhook.zoom');

// === LEAD MAGNET WEBHOOKS ===
// Отдельные эндпоинты от существующих /telegram/webhook и /vk-webhook (те — для user-уведомлений).
Route::post('/webhooks/telegram-magnet', [TelegramMagnetWebhookController::class, 'handle'])
    ->middleware('verify.tg.magnet')
    ->name('webhook.magnet.telegram');

// Per-bot: свой бот на каждый лендинг. {webhookKey} резолвит LandingBot,
// secret сверяется в middleware. Апдейты форвардятся в n8n (анкета/прогрев).
Route::post('/webhooks/telegram-magnet/{webhookKey}', [TelegramMagnetWebhookController::class, 'handlePerBot'])
    ->middleware('verify.tg.magnet')
    ->name('webhook.magnet.telegram.bot');

Route::post('/webhooks/vk-magnet', [VkMagnetCallbackController::class, 'handle'])
    ->middleware('verify.vk.magnet')
    ->name('webhook.magnet.vk');

Route::post('/webhooks/max-magnet/{secret}', [MaxMagnetWebhookController::class, 'handle'])
    ->middleware('verify.max.magnet')
    ->name('webhook.magnet.max');

// «Лид дошёл до шага бота» — n8n зовёт при достижении именованного шага сценария.
// Секрет в заголовке X-Webhook-Secret (services.n8n.lead_step_secret).
Route::post('/webhooks/lead-step', [LeadStepWebhookController::class, 'handle'])
    ->middleware('verify.n8n.leadstep')
    ->name('webhook.lead-step');

// Баллы экзамена «Санка» из Google-таблицы преподавателя — n8n читает лист по
// расписанию и шлёт батч сюда. Секрет в X-Webhook-Secret
// (services.n8n.exam_scores_secret). Санка-вехи автовыдаются только студентам
// с баллами (MilestoneCertificateIssuer).
Route::post('/webhooks/exam-scores', [ExamScoresWebhookController::class, 'handle'])
    ->middleware('verify.n8n.examscores')
    ->name('webhook.exam-scores');

// «Клипы нарезаны» (H1452, Wave 4) — n8n зовёт после ffmpeg-нарезки + VK-аплоада.
// Секрет в X-Webhook-Secret (services.n8n.clip_callback_secret); маршрут сам
// отвечает 404 при выключенном features.clip_marketing (см. контроллер).
Route::post('/webhooks/lecture-clip-callback', [LectureClipCallbackWebhookController::class, 'handle'])
    ->middleware('verify.n8n.clipcallback')
    ->name('webhook.lecture-clip-callback');

// === TELEGRAM TRACK C: @zapisi_ORSbot (H164, D8) ===
// Отдельный от /telegram/webhook (user-уведомления) и /webhooks/telegram-magnet
// (lead-magnet) эндпоинт для class-booking бота. Секрет из MarketingSetting.
Route::post('/webhooks/telegram-zapisi', [TelegramZapisiWebhookController::class, 'handle'])
    ->middleware('verify.tg.zapisi')
    ->name('webhook.zapisi.telegram');

// === ПАРТНЁРСКИЙ БОТ (агентская программа) ===
// Внешний Telegram-бот (@Partner_..._bot, ?start=agent) регистрирует партнёров
// и запрашивает их статистику. Общий секрет — заголовок X-Partner-Bot-Secret
// (config partner.bot_secret; обязателен, когда программа включена). throttle против перебора.
Route::prefix('partner-bot')->middleware(['verify.partner.bot', 'throttle:30,1'])->group(function () {
    Route::post('/register', [PartnerBotController::class, 'register'])->name('api.partner-bot.register');
    Route::post('/stats', [PartnerBotController::class, 'stats'])->name('api.partner-bot.stats');
});
