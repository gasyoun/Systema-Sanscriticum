<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
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

    /**
     * Посещаемость: участник зашёл/вышел. Одна строка на сессию участия
     * (schedule_id + participant_uuid), идемпотентно. Студент сопоставляется по
     * email (если Zoom его прислал), иначе остаётся гостем (user_id = NULL).
     */
    private function handleParticipant(Request $request, string $event): JsonResponse
    {
        $object = (array) $request->input('payload.object', []);
        $meetingId = isset($object['id']) ? (string) $object['id'] : '';

        /** @var Schedule|null $schedule */
        $schedule = $meetingId !== '' ? Schedule::where('zoom_meeting_id', $meetingId)->first() : null;
        if ($schedule === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'schedule not found']);
        }

        $p = (array) ($object['participant'] ?? []);

        // participant_uuid стабилен на одно подключение; фолбэк — user_id|join_time.
        $uuid = (string) ($p['participant_uuid'] ?? '');
        if ($uuid === '') {
            $uuid = (string) ($p['user_id'] ?? '').'|'.(string) ($p['join_time'] ?? '');
        }
        if (trim($uuid, '|') === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'no participant key']);
        }

        $email = ! empty($p['email']) ? (string) $p['email'] : null;
        $user = $email ? User::where('email', $email)->first() : null;

        $values = [
            'user_id' => $user?->id,
            'name' => $p['user_name'] ?? null,
            'email' => $email,
        ];
        if ($event === 'meeting.participant_joined') {
            $values['joined_at'] = $this->parseTime($p['join_time'] ?? null);
        } else {
            $values['left_at'] = $this->parseTime($p['leave_time'] ?? null);
            $values['duration_seconds'] = isset($p['duration']) ? (int) $p['duration'] : null;
        }

        WebinarAttendance::updateOrCreate(
            ['schedule_id' => $schedule->id, 'zoom_participant_uuid' => $uuid],
            // null-значения не затирают уже записанное (join не сбрасывает left, и наоборот).
            array_filter($values, fn ($v) => $v !== null),
        );

        return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id, 'event' => $event]);
    }

    private function parseTime(?string $time): ?string
    {
        return $time ? Carbon::parse($time)->toDateTimeString() : null;
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
