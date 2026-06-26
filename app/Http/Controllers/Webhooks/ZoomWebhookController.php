<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Zoom Event Subscription webhook (Фаза 2, #78).
 *
 * Обрабатывает:
 *  - `endpoint.url_validation` — челлендж при настройке эндпоинта (отдаём
 *    plainToken + его HMAC);
 *  - `recording.completed` — запись вебинара готова → находим Schedule по
 *    `zoom_meeting_id`, сохраняем ссылку и, если занятие привязано к курсу,
 *    идемпотентно заводим/обновляем урок (как push-intake from-zoom).
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

        if ($event === 'recording.completed') {
            return $this->handleRecordingCompleted($request);
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

    private function handleRecordingCompleted(Request $request): JsonResponse
    {
        $object = (array) $request->input('payload.object', []);
        $meetingId = isset($object['id']) ? (string) $object['id'] : '';

        if ($meetingId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'no meeting id']);
        }

        /** @var Schedule|null $schedule */
        $schedule = Schedule::where('zoom_meeting_id', $meetingId)->first();
        if ($schedule === null) {
            Log::info('Zoom webhook: recording.completed для неизвестной встречи', ['meeting_id' => $meetingId]);

            return response()->json(['status' => 'ignored', 'reason' => 'schedule not found']);
        }

        $recordingUrl = $this->pickRecordingUrl($object);

        $schedule->forceFill([
            'zoom_recording_url' => $recordingUrl,
            'zoom_recording_received_at' => now(),
        ])->save();

        $lessonId = null;
        if ($schedule->course_id && $recordingUrl) {
            $lessonId = $this->upsertLesson($schedule, $recordingUrl);
        }

        Log::info('Zoom webhook: запись привязана', [
            'schedule_id' => $schedule->id,
            'meeting_id' => $meetingId,
            'lesson_id' => $lessonId,
        ]);

        return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id, 'lesson_id' => $lessonId]);
    }

    /** Предпочитаем общую share_url встречи, иначе play_url первого MP4-файла. */
    private function pickRecordingUrl(array $object): ?string
    {
        if (! empty($object['share_url'])) {
            return (string) $object['share_url'];
        }

        foreach ((array) ($object['recording_files'] ?? []) as $file) {
            if (($file['file_type'] ?? null) === 'MP4' && ! empty($file['play_url'])) {
                return (string) $file['play_url'];
            }
        }

        return null;
    }

    /**
     * Идемпотентно по (course_id, group_id, дата занятия): повторная доставка
     * вебхука обновляет урок, а не плодит дубль. Зеркалит LessonController::storeFromZoom.
     */
    private function upsertLesson(Schedule $schedule, string $recordingUrl): int
    {
        $lessonDate = ($schedule->start ?? Carbon::now())->toDateString();

        $lesson = Lesson::query()
            ->where('course_id', $schedule->course_id)
            ->where('group_id', $schedule->group_id)
            ->whereDate('lesson_date', $lessonDate)
            ->first()
            ?? new Lesson([
                'course_id' => $schedule->course_id,
                'group_id' => $schedule->group_id,
                'lesson_date' => $lessonDate,
            ]);

        $lesson->fill([
            'title' => $schedule->title,
            'video_url' => $recordingUrl,
            'is_published' => true,
            'is_free' => false,
        ])->save();

        return $lesson->id;
    }
}
