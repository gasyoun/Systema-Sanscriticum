<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\Zoom\AttendanceRecorder;
use App\Services\Zoom\ZoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Zoom Event Subscription webhook (посещаемость, #78).
 *
 * Обрабатывает:
 *  - `endpoint.url_validation` — челлендж при настройке эндпоинта (отдаём
 *    plainToken + его HMAC);
 *  - `meeting.participant_joined` / `meeting.participant_left` — посещаемость:
 *    резолвим занятие по occurrence uuid + времени (единый meeting_id курса),
 *    пишем строку в `webinar_attendances`.
 *
 * Запись→урок обрабатывает отдельный n8n-воркфлоу (POST /api/lessons/from-zoom),
 * поэтому событие `recording.completed` здесь не трогаем.
 *
 * Подпись Zoom: `x-zm-signature: v0=<hmac_sha256("v0:{ts}:{body}", secret)>`.
 * Секрет обязателен: без него любое событие, включая URL-validation,
 * отвечает 503. HMAC с пустым ключом не вычисляется.
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = trim((string) config('services.zoom.webhook_secret', ''));
        $event = (string) $request->input('event', '');

        if ($secret === '') {
            Log::error('Zoom webhook: ZOOM_WEBHOOK_SECRET не задан');

            return response()->json(['message' => 'Webhook secret is not configured'], 503);
        }

        // URL-валидация подписывается тем же секретом, но проверка подписи на ней
        // не делается (Zoom шлёт её до того, как мы «доверены») — отвечаем челленджем.
        if ($event === 'endpoint.url_validation') {
            return $this->urlValidationResponse($request, $secret);
        }

        if (! $this->signatureValid($request, $secret)) {
            Log::warning('Zoom webhook: неверная подпись', ['ip' => $request->ip(), 'event' => $event]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        if ($event === 'meeting.participant_joined' || $event === 'meeting.participant_left') {
            return $this->handleParticipant($request, $event);
        }

        // Прочие события подтверждаем 200, чтобы Zoom не уходил в ретраи.
        return response()->json(['status' => 'ignored', 'event' => $event]);
    }

    private function urlValidationResponse(Request $request, string $secret): JsonResponse
    {
        $plainToken = (string) $request->input('payload.plainToken', '');

        return response()->json([
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, $secret),
        ]);
    }

    /** Проверка `x-zm-signature`; handle() гарантирует непустой секрет. */
    private function signatureValid(Request $request, string $secret): bool
    {
        $timestamp = (string) $request->header('x-zm-request-timestamp', '');
        $signature = (string) $request->header('x-zm-signature', '');
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        $message = 'v0:'.$timestamp.':'.$request->getContent();
        $expected = 'v0='.hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Посещаемость: участник зашёл/вышел. Одна строка на сессию участия
     * (schedule_id + participant_uuid), идемпотентно. Студент сопоставляется по
     * email (если Zoom его прислал), иначе остаётся гостем (user_id = NULL).
     */
    private function handleParticipant(Request $request, string $event): JsonResponse
    {
        // Нейтрализация payload'а живёт в драйвере (шов WebinarProvider, GC-B3);
        // разбор идентичен прежнему инлайновому — поведение байт-в-байт.
        $norm = app(ZoomService::class)->normalizeWebhook((array) $request->all());
        if ($norm === null) {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        // Единый meeting_id курса повторяется на всех датах — занятие резолвим по
        // occurrence uuid + времени запуска/участника.
        $schedule = Schedule::resolveForZoomEvent(
            $norm['meeting_id'], $norm['occurrence_uuid'], $norm['event_time'],
        );
        if ($schedule === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'schedule not found']);
        }

        app(AttendanceRecorder::class)->recordWebhookEvent($schedule->id, $norm['participant'], $norm['provider_event']);

        return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id, 'event' => $event]);
    }
}
