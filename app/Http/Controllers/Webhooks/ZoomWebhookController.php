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
 *  - `endpoint.url_validation` — челлендж при настройке эндпоинта: после
 *    проверки подписи отдаём plainToken + его HMAC (Zoom подписывает челлендж
 *    тем же v0-заголовком);
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
 * Подпись обязательна для всех событий, включая URL-validation: без неё
 * эндпоинт отвечал бы HMAC над секретом v0-подписи на тело от любого
 * анонима — signing oracle (H5046, закрыто в H5082).
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secrets = $this->webhookSecrets();
        $event = (string) $request->input('event', '');

        if ($secrets === []) {
            Log::error('Zoom webhook: ни одного webhook-секрета не задано (ZOOM_WEBHOOK_SECRET / ZOOM2_WEBHOOK_SECRET)');

            return response()->json(['message' => 'Webhook secret is not configured'], 503);
        }

        // События приходят от приложений всех аккаунтов школы (основной + доп.):
        // подпись сверяем с каждым секретом, валидной считаем первую совпавшую.
        $secret = null;
        foreach ($secrets as $candidate) {
            if ($this->signatureValid($request, $candidate)) {
                $secret = $candidate;
                break;
            }
        }

        if ($secret === null) {
            Log::warning('Zoom webhook: неверная подпись', ['ip' => $request->ip(), 'event' => $event]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // URL-валидация подписана тем же v0-секретом (Zoom шлёт x-zm-signature и
        // на челлендж) — HMAC-ответ отдаём только после проверки подписи:
        // иначе эндпоинт — неаутентифицированная HMAC signing oracle над секретом
        // v0-подписи (любое тело можно подписать, выбрав plainToken = "v0:{ts}:{body}").
        if ($event === 'endpoint.url_validation') {
            return $this->urlValidationResponse($request, $secret);
        }

        if ($event === 'meeting.participant_joined' || $event === 'meeting.participant_left') {
            return $this->handleParticipant($request, $event);
        }

        // Прочие события подтверждаем 200, чтобы Zoom не уходил в ретраи.
        return response()->json(['status' => 'ignored', 'event' => $event]);
    }

    /**
     * Непустые Secret Token'ы Event Subscriptions всех аккаунтов школы:
     * основной + дополнительные слоты services.zoom.extra_accounts.
     *
     * @return list<string>
     */
    private function webhookSecrets(): array
    {
        $secrets = [trim((string) config('services.zoom.webhook_secret', ''))];

        foreach ((array) config('services.zoom.extra_accounts', []) as $account) {
            $secrets[] = trim((string) ($account['webhook_secret'] ?? ''));
        }

        return array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));
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
