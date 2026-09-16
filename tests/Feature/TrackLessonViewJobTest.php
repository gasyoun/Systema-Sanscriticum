<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TrackLessonViewJob;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H4914: TrackLessonViewJob переживает ER_CHECKREAD 1020 после aborted
 * connection (MariaDB 11.8.6, инцидент 15-09-2026 ≈13:32–13:51 UTC,
 * user_id=6476, burst 12 ошибок на lesson_views/user_sessions).
 *
 * Дуга под тестом — ровно как на воркере: handle() бросает 1020 (Horizon
 * ретраит по backoff [10,30,60], failed_jobs — только при исчерпании tries),
 * транзакция откатилась атомарно, повторный запуск считает событие ровно
 * один раз. Дополнительно: duplicate-key гонка не задваивает
 * total_lessons_opened.
 */
class TrackLessonViewJobTest extends TestCase
{
    use RefreshDatabase;

    /** Одноразовый 1020 на первом insert в activity_events — последнем
     * стейтменте транзакции: к моменту сбоя lesson_views + счётчики уже
     * записаны, и откат обязан убрать их весь (как aborted connection
     * на проде). */
    private function armCheckRead1020(): void
    {
        DB::listen(function ($query) use (&$armed) {
            $armed ??= true;

            if ($armed && str_contains($query->sql, 'insert into "activity_events"')) {
                $armed = false;
                throw $this->checkRead1020($query->sql, $query->bindings);
            }
        });
    }

    /** Одноразовый duplicate-key на первом insert в lesson_views; перед
     * броском коммитим "конкурентную" строку (та же транзакция видит её
     * после catch — ветка перечитывания). */
    private function armDuplicateKeyRace(int $userId, int $lessonId, int $courseId): void
    {
        DB::listen(function ($query) use (&$armed, $userId, $lessonId, $courseId) {
            $armed ??= true;

            if ($armed && str_starts_with($query->sql, 'insert into "lesson_views"')) {
                $armed = false;
                DB::table('lesson_views')->insert([
                    'user_id' => $userId,
                    'lesson_id' => $lessonId,
                    'course_id' => $courseId,
                    'first_opened_at' => now(),
                    'last_opened_at' => now(),
                    'open_count' => 1,
                    'total_time_on_page' => 0,
                    'is_completed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw $this->duplicateKey($query->sql, $query->bindings);
            }
        });
    }

    private function checkRead1020(string $sql, array $bindings): QueryException
    {
        $message = "SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'lesson_views'";
        $pdo = new \PDOException($message, 1020);
        $pdo->errorInfo = ['HY000', 1020, $message];

        return new QueryException('sqlite', $sql, $bindings, $pdo);
    }

    private function duplicateKey(string $sql, array $bindings): QueryException
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7-1930' for key 'lesson_views_user_id_lesson_id_unique'";
        $pdo = new \PDOException($message, 1062);
        $pdo->errorInfo = ['23000', 1062, $message];

        return new QueryException('sqlite', $sql, $bindings, $pdo);
    }

    private function fixture(): array
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => (string) $course->id]);
        $user = User::factory()->create(['total_lessons_opened' => 0]);

        return [$user, $lesson, $course];
    }

    private function job(User $user, Lesson $lesson, Course $course, ?string $sessionId = null): TrackLessonViewJob
    {
        return new TrackLessonViewJob($user->id, $lesson->id, (int) $course->id, $sessionId);
    }

    public function test_clean_run_counts_everything_once(): void
    {
        [$user, $lesson, $course] = $this->fixture();

        $this->job($user, $lesson, $course)->handle();

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(1, $view->open_count);
        $this->assertSame(1, (int) $user->fresh()->total_lessons_opened);
        $this->assertSame(
            1,
            ActivityEvent::where('user_id', $user->id)->where('event_type', ActivityEvent::TYPE_LESSON_OPEN)->count(),
        );
    }

    public function test_repeat_view_increments_open_count_but_not_unique_counter(): void
    {
        [$user, $lesson, $course] = $this->fixture();

        $this->job($user, $lesson, $course)->handle();
        $this->job($user, $lesson, $course)->handle();

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(2, $view->open_count);
        $this->assertSame(1, (int) $user->fresh()->total_lessons_opened);
    }

    public function test_1020_aborts_transaction_and_retry_counts_exactly_once(): void
    {
        [$user, $lesson, $course] = $this->fixture();

        $this->armCheckRead1020();

        // Попытка 1: 1020 обязан вылететь из handle() — только тогда Horizon
        // заретраит по backoff и failed_jobs появится лишь при исчерпании tries.
        $thrown = null;

        try {
            $this->job($user, $lesson, $course, 'sess-1020')->handle();
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '1020 must propagate out of handle() so Horizon retries');
        $this->assertStringContainsString('1020', $thrown->getMessage());

        // Транзакция откатилась атомарно: НИЧЕГО не закоммичено.
        $this->assertSame(0, LessonView::count());
        $this->assertSame(0, (int) $user->fresh()->total_lessons_opened);
        $this->assertSame(0, ActivityEvent::count());

        // Попытка 2 (ретрай воркера после backoff): всё считается ровно один раз.
        $this->job($user, $lesson, $course, 'sess-1020')->handle();

        $this->assertSame(1, LessonView::count());
        $this->assertSame(1, (int) LessonView::sole()->open_count);
        $this->assertSame(1, (int) $user->fresh()->total_lessons_opened);
        $this->assertSame(1, ActivityEvent::count());
    }

    public function test_duplicate_key_race_does_not_double_count_unique_counter(): void
    {
        [$user, $lesson, $course] = $this->fixture();

        $this->armDuplicateKeyRace($user->id, $lesson->id, (int) $course->id);

        // create() ловит 1062, ветка перечитывания видит "конкурентную" строку,
        // обработка продолжается как повторный просмотр — без исключения наружу
        // (иначе failed_jobs по ошибке, которая уже решена) и без двойного
        // инкремента total_lessons_opened.
        $this->job($user, $lesson, $course, 'sess-race')->handle();

        $this->assertSame(1, LessonView::count());
        $view = LessonView::sole();
        $this->assertSame(2, $view->open_count);
        // isNewView=false → наш run не инкрементил (строку создал "конкурент").
        $this->assertSame(0, (int) $user->fresh()->total_lessons_opened);
    }

    public function test_duplicate_key_without_visible_row_is_rethrown(): void
    {
        [$user, $lesson, $course] = $this->fixture();

        // 1062 без видимой строки (гонка с ещё не закоммиченной вставкой):
        // честный ретрой — исключение уходит наружу, состояние не тронуто.
        DB::listen(function ($query) use (&$armed) {
            $armed ??= true;

            if ($armed && str_starts_with($query->sql, 'insert into "lesson_views"')) {
                $armed = false;
                throw $this->duplicateKey($query->sql, $query->bindings);
            }
        });

        $thrown = null;

        try {
            $this->job($user, $lesson, $course)->handle();
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'duplicate key with no visible row must propagate for retry');
        $this->assertSame(0, LessonView::count());
        $this->assertSame(0, (int) $user->fresh()->total_lessons_opened);
    }
}
