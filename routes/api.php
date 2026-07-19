<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WebhookController;
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

// === МОБИЛЬНОЕ ПРИЛОЖЕНИЕ (Sanctum personal access tokens) ===
Route::prefix('v1')->group(function () {
    // Публичный логин (выдаёт токен). Троттлим — публичный приём пароля.
    Route::post('/auth/login', [\App\Http\Controllers\Api\AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [\App\Http\Controllers\Api\AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/auth/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('/courses', [\App\Http\Controllers\Api\CabinetController::class, 'courses'])->name('api.courses');
        Route::get('/courses/{slug}/lessons', [\App\Http\Controllers\Api\CabinetController::class, 'lessons'])->name('api.courses.lessons');
    });
});

use App\Http\Controllers\Api\LessonController;

Route::post('/sync-lessons', [LessonController::class, 'sync']);
Route::post('/lessons/from-zoom', [LessonController::class, 'storeFromZoom']);

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('verify.tg.bot');

Route::post('/vk-webhook', [\App\Http\Controllers\Api\VkBotController::class, 'handle'])
    ->middleware('verify.vk.bot');

Route::post('/webhooks/tochka', [WebhookController::class, 'handleTochkaWebhook']);

// Zoom Event Subscription: запись вебинара готова (recording.completed) +
// проверка URL. Подпись (x-zm-signature) проверяется внутри контроллера.
Route::post('/webhooks/zoom', [\App\Http\Controllers\Webhooks\ZoomWebhookController::class, 'handle'])
    ->name('webhook.zoom');

// === LEAD MAGNET WEBHOOKS ===
// Отдельные эндпоинты от существующих /telegram/webhook и /vk-webhook (те — для user-уведомлений).
Route::post('/webhooks/telegram-magnet', [\App\Http\Controllers\Webhooks\TelegramMagnetWebhookController::class, 'handle'])
    ->middleware('verify.tg.magnet')
    ->name('webhook.magnet.telegram');

// Per-bot: свой бот на каждый лендинг. {webhookKey} резолвит LandingBot,
// secret сверяется в middleware. Апдейты форвардятся в n8n (анкета/прогрев).
Route::post('/webhooks/telegram-magnet/{webhookKey}', [\App\Http\Controllers\Webhooks\TelegramMagnetWebhookController::class, 'handlePerBot'])
    ->middleware('verify.tg.magnet')
    ->name('webhook.magnet.telegram.bot');

Route::post('/webhooks/vk-magnet', [\App\Http\Controllers\Webhooks\VkMagnetCallbackController::class, 'handle'])
    ->middleware('verify.vk.magnet')
    ->name('webhook.magnet.vk');

Route::post('/webhooks/max-magnet/{secret}', [\App\Http\Controllers\Webhooks\MaxMagnetWebhookController::class, 'handle'])
    ->middleware('verify.max.magnet')
    ->name('webhook.magnet.max');

// «Лид дошёл до шага бота» — n8n зовёт при достижении именованного шага сценария.
// Секрет в заголовке X-Webhook-Secret (services.n8n.lead_step_secret).
Route::post('/webhooks/lead-step', [\App\Http\Controllers\Webhooks\LeadStepWebhookController::class, 'handle'])
    ->middleware('verify.n8n.leadstep')
    ->name('webhook.lead-step');

// === TELEGRAM TRACK C: @zapisi_ORSbot (H164, D8) ===
// Отдельный от /telegram/webhook (user-уведомления) и /webhooks/telegram-magnet
// (lead-magnet) эндпоинт для class-booking бота. Секрет из MarketingSetting.
Route::post('/webhooks/telegram-zapisi', [\App\Http\Controllers\Webhooks\TelegramZapisiWebhookController::class, 'handle'])
    ->middleware('verify.tg.zapisi')
    ->name('webhook.zapisi.telegram');

// === ПАРТНЁРСКИЙ БОТ (агентская программа) ===
// Внешний Telegram-бот (@Partner_..._bot, ?start=agent) регистрирует партнёров
// и запрашивает их статистику. Общий секрет — заголовок X-Partner-Bot-Secret
// (config partner.bot_secret; enforce-if-configured). throttle против перебора.
Route::prefix('partner-bot')->middleware(['verify.partner.bot', 'throttle:30,1'])->group(function () {
    Route::post('/register', [\App\Http\Controllers\Api\PartnerBotController::class, 'register'])->name('api.partner-bot.register');
    Route::post('/stats', [\App\Http\Controllers\Api\PartnerBotController::class, 'stats'])->name('api.partner-bot.stats');
});
