<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Models\Course;
use App\Models\Lesson;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Бэкфилл recording_attached_at (H1935, issue #868).
 *
 * Ловушка, которую эти тесты держат закрытой: команда чинит ТРИГГЕР, а не
 * открывает приём. Если она когда-нибудь начнёт трогать homework_enabled или
 * слать уведомления, прогон по архиву разошлёт пуши по всем прошедшим урокам.
 */
class BackfillRecordingStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Http::fake();

        date_default_timezone_set('Europe/Moscow');

        config([
            'homework.auto_open.delay_hours' => 12,
            'homework.auto_open.align_hour' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function dry_run_is_the_default_and_writes_nothing(): void
    {
        $lesson = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);

        $this->artisan('lessons:backfill-recording-stamp')->assertSuccessful();

        $lesson->refresh();
        $this->assertNull($lesson->recording_attached_at);
        $this->assertNull($lesson->homework_opens_at);
    }

    /** @test */
    public function apply_reconstructs_the_stamp_from_lesson_date(): void
    {
        $lesson = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);

        $this->artisan('lessons:backfill-recording-stamp --apply')->assertSuccessful();

        $lesson->refresh();
        $this->assertSame('2026-07-21 00:00:00', $lesson->recording_attached_at->format('Y-m-d H:i:s'));

        // Опора в полночь + 12 ч = 12:00, выравнивание на 09:00 уже прошло —
        // значит следующее утро. Это и есть «ДЗ открывается назавтра».
        $this->assertSame('2026-07-22 09:00:00', $lesson->homework_opens_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function an_existing_stamp_is_never_overwritten(): void
    {
        $lesson = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);
        $lesson->forceFill(['recording_attached_at' => '2026-07-25 18:00:00'])->saveQuietly();

        $this->artisan('lessons:backfill-recording-stamp --apply')->assertSuccessful();

        $this->assertSame(
            '2026-07-25 18:00:00',
            $lesson->fresh()->recording_attached_at->format('Y-m-d H:i:s')
        );
    }

    /** @test */
    public function a_lesson_without_any_recording_is_untouched(): void
    {
        $lesson = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);
        $lesson->forceFill(['youtube_url' => null, 'rutube_url' => null, 'video_url' => null])->saveQuietly();

        $this->artisan('lessons:backfill-recording-stamp --apply')->assertSuccessful();

        $this->assertNull($lesson->fresh()->recording_attached_at);
    }

    /** @test */
    public function backfill_never_opens_homework_and_never_notifies(): void
    {
        $lesson = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);

        $this->artisan('lessons:backfill-recording-stamp --apply')->assertSuccessful();

        $lesson->refresh();
        $this->assertFalse((bool) $lesson->homework_enabled);
        $this->assertNull($lesson->homework_prompt);
        $this->assertNull($lesson->homework_auto_opened_at);

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    /** @test */
    public function the_course_filter_scopes_the_run(): void
    {
        $mine = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);
        $other = $this->lessonWithVideoAndNoStamp(['lesson_date' => '2026-07-21']);

        $this->artisan('lessons:backfill-recording-stamp --apply --course='.$mine->course->slug)
            ->assertSuccessful();

        $this->assertNotNull($mine->fresh()->recording_attached_at);
        $this->assertNull($other->fresh()->recording_attached_at);
    }

    private function lessonWithVideoAndNoStamp(array $attributes = []): Lesson
    {
        $lesson = Lesson::factory()->for(Course::factory()->create())->create($attributes + [
            'youtube_url' => 'https://youtu.be/'.uniqid(),
            'homework_enabled' => false,
        ]);

        // Хук Lesson::boot() штампует урок при сохранении с видео — снимаем штамп,
        // воспроизводя ровно то состояние, ради которого команда написана.
        $lesson->forceFill(['recording_attached_at' => null, 'homework_opens_at' => null])->saveQuietly();

        return $lesson->fresh();
    }
}
