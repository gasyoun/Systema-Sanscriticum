<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\HeartbeatRequest;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Services\Access\LessonGate;
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

        // Server-side throttle вынесен ВЫШЕ любых SQL-запросов: троттлированные
        // вызовы должны быть максимально дешёвыми (Redis-only).
        $throttleKey = "heartbeat:{$user->id}:{$lessonId}";

        try {
            $acquired = Redis::set(
                $throttleKey,
                (string) time(),
                'EX',
                self::MIN_INTERVAL_SECONDS,
                'NX'
            );

            if (! $acquired) {
                return response()->json(['ok' => true, 'throttled' => true]);
            }
        } catch (\Throwable $e) {
            // H3315: Redis лёг — консервативно ПРОПУСКАЕМ запись watch-time
            // (fail-open раздувал статистику повторными дельтами), но клиенту
            // отвечаем как throttled — телеметрия не имеет права ронять плеер.
            Log::warning('Heartbeat throttle Redis failed — skipping write', ['error' => $e->getMessage()]);

            return response()->json(['ok' => true, 'throttled' => true]);
        }

        // H3315: watch-time пишется только за урок, который студент реально
        // может смотреть, — тот же гейт, что у плеера (единый LessonGate,
        // цепочка StudentController::showLesson / H3308 GatedAssetController).
        // Не прошедшим — 404 (no-oracle: неотличимо от «урока нет»).
        $lesson = Lesson::find($lessonId);
        if ($lesson === null || ! app(LessonGate::class)->canWatch($user, $lesson)) {
            return response()->json(['ok' => false], 404);
        }

        // Cold-path: lesson_views ещё не существует — course_id берём из уже
        // загруженного урока. На hot-path (запись есть) второй запрос не нужен.
        $view = LessonView::firstOrNew(
            ['user_id' => $user->id, 'lesson_id' => $lessonId]
        );

        if (! $view->exists) {
            $view->fill([
                'course_id' => $lesson->course_id,
                'first_opened_at' => now(),
                'last_opened_at' => now(),
                'last_heartbeat_at' => now(),
                'open_count' => 1,
                'total_time_on_page' => 0,
                'is_completed' => false,
            ])->save();
        }

        $updates = [
            'last_heartbeat_at' => now(),
            'total_time_on_page' => DB::raw('total_time_on_page + '.(int) $delta),
        ];

        // In-video resume (H1450, W2). position/duration необязательны — хост без
        // API текущей позиции (или флаг video_resume выключен на клиенте) просто
        // не шлёт их, и запись ведёт себя ровно как раньше.
        //
        // max_position_seconds монотонный: считаем в PHP через $view (firstOrNew
        // уже загрузил существующую строку на hot-path), а не SQL GREATEST — та
        // функция не портируется на SQLite (тесты), только на MySQL.
        $position = $request->validated('position');
        if ($position !== null) {
            $position = (int) $position;
            $updates['last_position_seconds'] = $position;
            $updates['max_position_seconds'] = max((int) ($view->max_position_seconds ?? 0), $position);
        }

        $duration = $request->validated('duration');
        if ($duration !== null) {
            $updates['video_duration_seconds'] = (int) $duration;
        }

        // Инкрементим счётчик и пишем heartbeat. Одним запросом, через DB::table для скорости.
        DB::table('lesson_views')
            ->where('id', $view->id)
            ->update($updates);

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
