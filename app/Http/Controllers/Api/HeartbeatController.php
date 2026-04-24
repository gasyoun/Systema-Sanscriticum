<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\HeartbeatRequest;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Принимает heartbeat-пинги с урока.
 *
 * Работает поверх обычного web middleware (auth + session),
 * чтобы иметь доступ к текущему пользователю и session_id.
 * CSRF-токен прилетает из JS вместе с запросом.
 */
final class HeartbeatController extends Controller
{
    /** Минимальный интервал между heartbeat'ами одного юзера на одном уроке */
    private const MIN_INTERVAL_SECONDS = 20;

    /** Максимальный накопленный прирост за один запрос */
    private const MAX_DELTA_SECONDS = 90;

    public function store(HeartbeatRequest $request): JsonResponse
    {
        $user = $request->user();
        $lessonId = (int) $request->validated('lesson_id');
        $delta = (int) $request->validated('delta_seconds');

        // Защита от подделанных delta на клиенте.
        // Финальная защита от "watched: 99999" помимо FormRequest правил.
        $delta = min($delta, self::MAX_DELTA_SECONDS);

        // Находим запись просмотра. Если её нет — значит что-то пошло не так
        // (студент должен был сначала открыть урок → TrackLessonViewJob создать запись).
        // Но может быть race condition — job ещё не успел. Создадим запись здесь.
        $lesson = Lesson::find($lessonId);
        if (!$lesson) {
            return response()->json(['ok' => false], 404);
        }

        $view = LessonView::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lessonId],
            [
                'course_id'          => $lesson->course_id,
                'first_opened_at'    => now(),
                'last_opened_at'     => now(),
                'last_heartbeat_at'  => now(),
                'open_count'         => 1,
                'total_time_on_page' => 0,
                'is_completed'       => false,
            ]
        );

        // Server-side throttle: не даём клиенту долбить чаще чем раз в MIN_INTERVAL_SECONDS
        // Защита от агрессивной накрутки time-on-page.
        $throttleKey = "heartbeat:{$user->id}:{$lessonId}";

        try {
            $acquired = Redis::set(
                $throttleKey,
                (string) time(),
                'EX',
                self::MIN_INTERVAL_SECONDS,
                'NX'
            );

            if (!$acquired) {
                // Слишком часто — вежливо отвечаем OK, но не обновляем
                return response()->json(['ok' => true, 'throttled' => true]);
            }
        } catch (\Throwable $e) {
            Log::warning('Heartbeat throttle Redis failed', ['error' => $e->getMessage()]);
            // Даже если Redis упал — не блокируем heartbeat
        }

        // Инкрементим счётчик и пишем heartbeat. Одним запросом, через DB::table для скорости.
        DB::table('lesson_views')
            ->where('id', $view->id)
            ->update([
                'last_heartbeat_at'  => now(),
                'total_time_on_page' => DB::raw('total_time_on_page + ' . (int) $delta),
            ]);

        // Заодно тикаем heartbeat активной сессии (чтобы cron-закрыватель не убил её).
        // Юзер на странице урока = сессия живая.
        DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->where('is_active', true)
            ->update(['last_heartbeat_at' => now()]);

        return response()->json(['ok' => true]);
    }
}