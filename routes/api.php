<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
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

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::post('/vk-webhook', [\App\Http\Controllers\Api\VkBotController::class, 'handle']);

Route::post('/webhooks/tochka', [WebhookController::class, 'handleTochkaWebhook']);
