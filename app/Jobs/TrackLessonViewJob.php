<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job для трекинга открытия урока.
 *
 * Почему отдельный job, а не синхронно в контроллере:
 * - Не замедляет рендер страницы урока
 * - Если упадёт (например, БД недоступна) — не ломает юзеру просмотр
 * - Ретраится через Horizon при временных сбоях
 * - Инкапсулирует всю логику обновления 4 таблиц в одном месте
 */
final class TrackLessonViewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Сколько раз ретраить при падении */
    public int $tries = 3;

    /** Backoff между попытками в секундах */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly int $userId,
        public readonly int $lessonId,
        public readonly int $courseId,
        public readonly ?string $laravelSessionId = null,
        public readonly ?string $url = null,
        public readonly ?string $ipAddress = null,
    ) {
        // Кладём job в отдельную очередь "tracking" чтобы при необходимости
        // можно было дать ей меньше воркеров и не блокировать критичные очереди
        // (отправку платежей, писем и т.д.)
        $this->onQueue('tracking');
    }

    public function handle(): void
    {
        // Защитный slice — если запись исчезла, пока job висел в очереди
        $user   = User::find($this->userId);
        $lesson = Lesson::find($this->lessonId);

        if (!$user || !$lesson) {
            // Молча завершаемся — нет смысла ретраить, данные пропали
            return;
        }

        try {
            DB::transaction(function () use ($user, $lesson) {
                $isNewView = $this->upsertLessonView($user->id, $lesson->id);
                $this->updateUserCounters($user, $isNewView);
                $this->incrementSessionLessons();
                $this->logEvent($user, $lesson);
            });
        } catch (\Throwable $e) {
            Log::warning('TrackLessonViewJob failed', [
                'user_id'   => $this->userId,
                'lesson_id' => $this->lessonId,
                'error'     => $e->getMessage(),
            ]);
            // Пробрасываем — Horizon заретраит по backoff
            throw $e;
        }
    }

    /**
     * Upsert записи в lesson_views.
     *
     * @return bool true — если это был первый просмотр урока (новая строка)
     */
    private function upsertLessonView(int $userId, int $lessonId): bool
    {
        // Смотрим, есть ли уже запись (чтобы вернуть флаг "первый просмотр")
        $existing = LessonView::where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->first();

        if ($existing === null) {
            LessonView::create([
                'user_id'            => $userId,
                'lesson_id'          => $lessonId,
                'course_id'          => $this->courseId,
                'first_opened_at'    => now(),
                'last_opened_at'     => now(),
                'open_count'         => 1,
                'total_time_on_page' => 0,
                'is_completed'       => false,
            ]);
            return true; // новый просмотр
        }

        // Повторный просмотр — апдейтим счётчик и дату
        $existing->update([
            'last_opened_at' => now(),
            'open_count'     => $existing->open_count + 1,
        ]);

        return false;
    }

    /**
     * Обновляем счётчик уникальных просмотренных уроков у юзера —
     * только если это был первый просмотр этого урока.
     */
    private function updateUserCounters(User $user, bool $isNewView): void
    {
        if (!$isNewView) {
            return;
        }

        // Используем forceFill + save вместо increment() чтобы не триггерить лишние события
        DB::table('users')
            ->where('id', $user->id)
            ->increment('total_lessons_opened');
    }

    /**
     * Инкрементим счётчик уроков в активной сессии.
     */
    private function incrementSessionLessons(): void
    {
        if ($this->laravelSessionId === null) {
            return;
        }

        DB::table('user_sessions')
            ->where('user_id', $this->userId)
            ->where('session_id', $this->laravelSessionId)
            ->where('is_active', true)
            ->increment('lessons_viewed');
    }

    /**
     * Пишем сырое событие в activity_events.
     */
    private function logEvent(User $user, Lesson $lesson): void
    {
        // Находим id активной сессии для связи события
        $sessionRecord = $this->laravelSessionId
            ? UserSession::where('user_id', $this->userId)
                ->where('session_id', $this->laravelSessionId)
                ->where('is_active', true)
                ->first()
            : null;

        DB::table('activity_events')->insert([
            'user_id'    => $user->id,
            'session_id' => $sessionRecord?->id,
            'event_type' => ActivityEvent::TYPE_LESSON_OPEN,
            'event_data' => json_encode([
                'lesson_id'    => $lesson->id,
                'lesson_title' => $lesson->title,
                'course_id'    => $this->courseId,
                'block_number' => $lesson->block_number,
            ], JSON_UNESCAPED_UNICODE),
            'url'        => $this->url ? mb_substr($this->url, 0, 500) : null,
            'ip_address' => $this->ipAddress,
            'created_at' => now(),
        ]);
    }
}