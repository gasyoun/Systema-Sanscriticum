<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\AttendanceRecorder;
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
 * При заданном секрете — fail-closed; пустой секрет (локалка) — пропускаем с логом.
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.zoom.webhook_secret', '');
        $event = (string) $request->input('event', '');

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

    /**
     * Проверка `x-zm-signature`. Пустой секрет → пропускаем (enforce-if-configured).
     */
    private function signatureValid(Request $request, string $secret): bool
    {
        if ($secret === '') {
            Log::warning('Zoom webhook: секрет не задан — подпись не проверяется');

            return true;
        }

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
     *
     * Разбор payload вынесен в {@see WebinarProvider::normalizeWebhook()} (H601,
     * GC-B3) — здесь та же форма результата, что и раньше, транспортный слой
     * (сигнатура, url_validation) не тронут.
     */
    private function handleParticipant(Request $request, string $event): JsonResponse
    {
        $normalized = app(WebinarProvider::class)->normalizeWebhook($request->all());
        if ($normalized === null) {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        $schedule = Schedule::resolveForZoomEvent(
            $normalized['meeting_id'],
            $normalized['occurrence_uuid'],
            $normalized['event_time'],
        );
        if ($schedule === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'schedule not found']);
        }

        app(AttendanceRecorder::class)->recordWebhookEvent($schedule->id, $normalized['participant'], $event);

        return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id, 'event' => $event]);
    }
}
