<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TrackLessonViewJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackLessonViewJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_is_counted_exactly_once_after_a_retry(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();
        $user = User::factory()->create(['total_lessons_opened' => 0]);

        // Первый прогон — как если бы предыдущая попытка Horizon откатилась
        // целиком (DB::transaction откатывает 1020 атомарно) и это повторный запуск.
        (new TrackLessonViewJob($user->id, $lesson->id, (int) $course->id))->handle();
        (new TrackLessonViewJob($user->id, $lesson->id, (int) $course->id))->handle();

        $this->assertSame(1, LessonView::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->count());

        $view = LessonView::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();
        $this->assertSame(2, $view->open_count);

        // total_lessons_opened считает УНИКАЛЬНЫЕ уроки, растёт только на первом просмотре
        $this->assertSame(1, $user->fresh()->total_lessons_opened);
    }

    public function test_isCheckreadError_matches_only_mysql_error_1020(): void
    {
        // errorInfo[1] — это код, который PDO MySQL реально кладёт при ER_CHECKREAD;
        // проверяем распознавание без похода в настоящую MySQL (тесты на SQLite).
        $job = new TrackLessonViewJob(1, 1, 1);

        $reflection = new \ReflectionClass(TrackLessonViewJob::class);
        $isCheckread = $reflection->getMethod('isCheckreadError');
        $isCheckread->setAccessible(true);

        // PDO не заполняет errorInfo из аргумента конструктора — как и настоящий
        // MySQL-драйвер, выставляем его вручную: ['SQLSTATE', driver_code, message].
        $checkreadPdo = new \PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read');
        $checkreadPdo->errorInfo = ['HY000', 1020, "Record has changed since last read in table 'lesson_views'"];
        $checkread = new QueryException('mysql', 'sql', [], $checkreadPdo);
        $this->assertTrue($isCheckread->invoke($job, $checkread));

        $duplicatePdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $duplicatePdo->errorInfo = ['23000', 1062, "Duplicate entry '1-1' for key 'lesson_views_user_id_lesson_id_unique'"];
        $duplicateKey = new QueryException('mysql', 'sql', [], $duplicatePdo);
        $this->assertFalse($isCheckread->invoke($job, $duplicateKey));
    }

    public function test_missing_user_or_lesson_returns_silently_without_writing_rows(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();

        (new TrackLessonViewJob(999999, $lesson->id, (int) $course->id))->handle();

        $this->assertSame(0, LessonView::where('lesson_id', $lesson->id)->count());
    }
}
