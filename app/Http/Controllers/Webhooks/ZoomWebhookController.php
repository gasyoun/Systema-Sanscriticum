<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\Zoom\AttendanceRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Zoom Event Subscription webhook (посещаемость, #78).
 *
 * Обрабатывает:
 *  - `endpoint.url_validation` — челлендж при настройке эндпоинта, только
 *    когда временно включён `services.zoom.url_validation_enabled`;
 *  - `meeting.participant_joined` / `meeting.participant_left` — посещаемость:
 *    резолвим занятие по occurrence uuid + времени (единый meeting_id курса),
 *    пишем строку в `webinar_attendances`.
 *
 * Запись→урок обрабатывает отдельный n8n-воркфлоу (POST /api/lessons/from-zoom),
 * поэтому событие `recording.completed` здесь не трогаем.
 *
 * Подпись Zoom: `x-zm-signature: v0=<hmac_sha256("v0:{ts}:{body}", secret)>`.
 * При заданном секрете — fail-closed; пустой секрет допускается только локально/в тестах.
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.zoom.webhook_secret', '');
        $event = (string) $request->input('event', '');

        if ($event === 'endpoint.url_validation') {
            if (! (bool) config('services.zoom.url_validation_enabled', false) || $secret === '') {
                Log::warning('Zoom webhook: URL validation отключена или секрет не задан', ['ip' => $request->ip()]);

                return response()->json(['message' => 'URL validation disabled'], 403);
            }

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
     * Проверка `x-zm-signature`.
     */
    private function signatureValid(Request $request, string $secret): bool
    {
        if ($secret === '') {
            if (app()->environment(['local', 'testing'])) {
                Log::warning('Zoom webhook: секрет не задан — подпись не проверяется');

                return true;
            }

            Log::warning('Zoom webhook: секрет не задан — запрос отклонён');

            return false;
        }

        $timestamp = (string) $request->header('x-zm-request-timestamp', '');
        $signature = (string) $request->header('x-zm-signature', '');
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $sentAt = Carbon::createFromTimestamp((int) $timestamp);
        if ($sentAt->lt(now()->subMinutes(5)) || $sentAt->gt(now()->addMinutes(5))) {
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
        $object = (array) $request->input('payload.object', []);
        $meetingId = isset($object['id']) ? (string) $object['id'] : '';
        $occurrenceUuid = isset($object['uuid']) ? (string) $object['uuid'] : null;

        $p = (array) ($object['participant'] ?? []);

        // Единый meeting_id курса повторяется на всех датах — занятие резолвим по
        // occurrence uuid + времени запуска/участника.
        $eventTime = $object['start_time'] ?? ($p['join_time'] ?? null);

        $schedule = Schedule::resolveForZoomEvent($meetingId, $occurrenceUuid, $eventTime);
        if ($schedule === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'schedule not found']);
        }

        app(AttendanceRecorder::class)->recordWebhookEvent($schedule->id, $p, $event);

        return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id, 'event' => $event]);
    }
}
