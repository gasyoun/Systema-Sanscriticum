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
use App\Http\Controllers\Api\LessonController;

Route::post('/sync-lessons', [LessonController::class, 'sync']);
Route::post('/lessons/from-zoom', [LessonController::class, 'storeFromZoom']);

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('verify.tg.bot');

Route::post('/vk-webhook', [\App\Http\Controllers\Api\VkBotController::class, 'handle'])
    ->middleware('verify.vk.bot');

Route::post('/webhooks/tochka', [WebhookController::class, 'handleTochkaWebhook']);

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
