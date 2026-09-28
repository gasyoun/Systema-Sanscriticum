<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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
        $user = User::find($this->userId);
        $lesson = Lesson::find($this->lessonId);

        if (! $user || ! $lesson) {
            // H5298: раньше — полное молчание, и выпавший lesson_open был
            // неотличим от «джоба не запускалась». Ретраить правда нечего, но
            // пропуск обязан быть громким и машиночитаемым (контракт H5061/H5298,
            // state-ключ): источник исчез = unavailable, НЕ ноль и НЕ успех.
            // Джоба НЕ падает (ретрай по несуществующим id бессмысленный).
            Log::warning('TrackLessonViewJob: источник просмотра исчез, событие не записано', [
                'state' => 'unavailable',
                'user_id' => $this->userId,
                'lesson_id' => $this->lessonId,
                'user_found' => $user !== null,
                'lesson_found' => $lesson !== null,
            ]);

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
            // ER_CHECKREAD 1020 — MariaDB возвращает его на InnoDB, когда транзакция
            // read-then-INSERT/UPDATE едет по соединению с протухшим row-handler
            // после "Aborted connection" (инцидент 15-09-2026: 2 aborted connections
            // → 12 ошибок 1020 на lesson_views/user_sessions, self-recovered).
            // Транзакция откатилась атомарно — ничего не закоммичено, повторный
            // запуск пересчитает всё с чистого листа. Бросаем дальше: Horizon
            // ретраит по backoff [10, 30, 60], failed_jobs пишется ТОЛЬКО когда
            // все tries=3 исчерпаны — успешный ретрай строки в failed_jobs не даёт.
            //
            // Ловим \Throwable, а не только QueryException: Laravel сам признаёт
            // "Record has changed since last read" concurrency-ошибкой и под
            // вложенной транзакцией оборачивает её в DeadlockException —
            // классифицируем по сообщению/errorInfo независимо от обёртки.
            if ($this->isCheckReadError($e)) {
                [$sqlstate, $driverCode] = $this->driverErrorInfo($e);

                Log::warning('TrackLessonViewJob retryable ER_CHECKREAD 1020', [
                    'user_id' => $this->userId,
                    'lesson_id' => $this->lessonId,
                    'sqlstate' => $sqlstate,
                    'driver_code' => $driverCode,
                    'attempt' => $this->attempts(),
                    'hint' => 'transaction rolled back atomically; Horizon backoff absorbs, no failed_jobs row unless tries exhausted',
                ]);

                throw $e;
            }

            Log::warning('TrackLessonViewJob failed', [
                'user_id' => $this->userId,
                'lesson_id' => $this->lessonId,
                'error' => $e->getMessage(),
            ]);
            // Пробрасываем — Horizon заретраит по backoff
            throw $e;
        }
    }

    /**
     * ER_CHECKREAD (1020): "Record has changed since last read in table ...".
     * SQLSTATE = HY000, код драйвера = 1020.
     *
     * Проверяем и driver-код из errorInfo, и текст драйвера: обёртки
     * (QueryException, DeadlockException) не во всех путях доносят errorInfo,
     * а сообщение всегда содержит сигнатуру целиком ("SQLSTATE[HY000]:
     * General error: 1020 Record has changed since last read in table ...").
     */
    private function isCheckReadError(\Throwable $e): bool
    {
        $info = $e instanceof QueryException ? ($e->errorInfo ?? null) : null;

        if (is_array($info) && (int) ($info[1] ?? 0) === 1020) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'Record has changed since last read')
            && preg_match('/\b1020\b/', $message) === 1;
    }

    /**
     * Разобранная пара [SQLSTATE, driver-code] для логов: errorInfo, если
     * дошёл, иначе парсинг из сообщения ("SQLSTATE[HY000]: General error:
     * 1020 ..." → ['HY000', 1020]).
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function driverErrorInfo(\Throwable $e): array
    {
        $info = $e instanceof QueryException ? ($e->errorInfo ?? null) : null;

        if (is_array($info) && isset($info[0], $info[1])) {
            return [(string) $info[0], (int) $info[1]];
        }

        if (preg_match('/SQLSTATE\[(\w+)\].*?\b(\d{4})\b/', $e->getMessage(), $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /**
     * Upsert записи в lesson_views.
     *
     * Идемпотентность под ретрай (H4914): транзакция атомарна — если она
     * упала (1020 после aborted connection), откатились и счётчики
     * total_lessons_opened / lessons_viewed вместе с ней; повторный запуск
     * перечитывает коммитнутое состояние. Единственная оставшаяся гонка —
     * два конкурентных handle() одновременно видят "строки нет": оба делают
     * create(), второй ловит duplicate-key (unique user_id+lesson_id) —
     * перечитываем строку и считаем её существующей, счётчик юзера не
     * инкрементится второй раз.
     *
     * @return bool true — если это был первый просмотр урока (новая строка)
     */
    private function upsertLessonView(int $userId, int $lessonId): bool
    {
        try {
            // Смотрим, есть ли уже запись (чтобы вернуть флаг "первый просмотр")
            $existing = LessonView::where('user_id', $userId)
                ->where('lesson_id', $lessonId)
                ->first();

            if ($existing === null) {
                LessonView::create([
                    'user_id' => $userId,
                    'lesson_id' => $lessonId,
                    'course_id' => $this->courseId,
                    'first_opened_at' => now(),
                    'last_opened_at' => now(),
                    'open_count' => 1,
                    'total_time_on_page' => 0,
                    'is_completed' => false,
                ]);

                return true; // новый просмотр
            }
        } catch (QueryException $e) {
            // 23000/1062 duplicate key: конкурентный запуск уже создал строку
            // (или мы сами на ретрае после lost-ack). Перечитываем и считаем
            // существующим просмотром — double-count невозможен.
            if ($this->isDuplicateKeyError($e)) {
                $existing = LessonView::where('user_id', $userId)
                    ->where('lesson_id', $lessonId)
                    ->first();

                if ($existing === null) {
                    // Строка не видна (гонка с ещё не закоммиченной вставкой) —
                    // дублирующее исключение честно уходит в общий ретрай.
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        // Повторный просмотр — апдейтим счётчик и дату
        $existing->update([
            'last_opened_at' => now(),
            'open_count' => $existing->open_count + 1,
        ]);

        return false;
    }

    /**
     * Duplicate key по unique (user_id, lesson_id): SQLSTATE 23000, код 1062.
     * Формы сообщений: MariaDB "Duplicate entry '...' for key ...",
     * sqlite "UNIQUE constraint failed: ..." (тесты).
     */
    private function isDuplicateKeyError(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;

        if (is_array($info) && ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 1062) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, '23000')
            && (str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed'));
    }

    /**
     * Обновляем счётчик уникальных просмотренных уроков у юзера —
     * только если это был первый просмотр этого урока.
     */
    private function updateUserCounters(User $user, bool $isNewView): void
    {
        if (! $isNewView) {
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
            'user_id' => $user->id,
            'session_id' => $sessionRecord?->id,
            'event_type' => ActivityEvent::TYPE_LESSON_OPEN,
            'event_data' => json_encode([
                'lesson_id' => $lesson->id,
                'lesson_title' => $lesson->title,
                'course_id' => $this->courseId,
                'block_number' => $lesson->block_number,
            ], JSON_UNESCAPED_UNICODE),
            'url' => $this->url ? mb_substr($this->url, 0, 500) : null,
            'ip_address' => $this->ipAddress,
            'created_at' => now(),
        ]);
    }
}
