<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Generic/Hindi track: immediate homework open with fixed prompt after first recording.
 */
class GenericAutoOpenHomeworkTest extends TestCase
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
            'homework.auto_open.enabled' => true,
            'homework.auto_open.course_slugs' => [],
            'homework.auto_open.generic_course_slugs' => ['hindi-pilot'],
            'homework.auto_open.generic_prompt' => 'Домашнее задание',
            'homework.auto_open.generic_immediate' => true,
            'homework.auto_open.notify_channels' => ['telegram'],
            'services.telegram.student_bot_token' => 'TESTTOKEN',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function first_recording_opens_homework_with_generic_prompt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 18:30', 'Europe/Moscow'));

        $course = Course::factory()->create(['slug' => 'hindi-pilot', 'title' => 'Хинди']);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Урок 3',
            'homework_enabled' => false,
            'homework_prompt' => null,
        ]);

        $this->assertFalse((bool) $lesson->fresh()->homework_enabled);

        $lesson->update(['youtube_url' => 'https://youtu.be/hindi-lesson-3']);

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled);
        $this->assertSame('Домашнее задание', $lesson->homework_prompt);
        $this->assertNotNull($lesson->homework_auto_opened_at);
        $this->assertNotNull($lesson->recording_attached_at);
    }

    /**
     * Zoom/n8n path: new Lesson + video in one INSERT. Laravel does not set
     * wasChanged() on create — open must still fire (prod regression 1830/1832).
     *
     * @test
     */
    public function create_with_video_opens_homework_immediately(): void
    {
        $course = Course::factory()->create(['slug' => 'hindi-pilot']);

        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Новый урок с записью',
            'youtube_url' => 'https://youtu.be/create-with-video',
            'homework_enabled' => false,
            'homework_prompt' => null,
        ]);

        $lesson->refresh();
        $this->assertNotNull($lesson->recording_attached_at);
        $this->assertTrue((bool) $lesson->homework_enabled, 'CREATE+video must auto-open HW');
        $this->assertSame('Домашнее задание', $lesson->homework_prompt);
        $this->assertNotNull($lesson->homework_auto_opened_at);
    }

    /** @test */
    public function second_video_save_does_not_reopen_or_second_push(): void
    {
        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $student = User::factory()->create(['telegram_id' => '777001']);
        $group->users()->attach($student->id);

        $lesson = Lesson::factory()->for($course)->create([
            'group_id' => $group->id,
            'title' => 'Урок 4',
        ]);

        $lesson->update(['youtube_url' => 'https://youtu.be/first']);
        $openedAt = $lesson->fresh()->homework_auto_opened_at;
        $this->assertNotNull($openedAt);
        $pushesAfterFirst = count(Http::recorded());
        $this->assertGreaterThan(0, $pushesAfterFirst, 'Ожидали пуш студенту при первом открытии.');

        $lesson->update(['youtube_url' => 'https://youtu.be/second']);

        $this->assertTrue(
            $openedAt->equalTo($lesson->fresh()->homework_auto_opened_at),
            'Повторная заливка сдвинула homework_auto_opened_at.',
        );
        $this->assertSame('Домашнее задание', $lesson->fresh()->homework_prompt);
        $this->assertCount(
            $pushesAfterFirst,
            Http::recorded(),
            'Повторная заливка отправила второй пуш.',
        );
    }

    /** @test */
    public function teacher_written_prompt_is_preserved(): void
    {
        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_prompt' => 'Выучите диалог на с. 12',
            'homework_enabled' => false,
        ]);

        $lesson->update(['youtube_url' => 'https://youtu.be/with-prompt']);

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled);
        $this->assertSame('Выучите диалог на с. 12', $lesson->homework_prompt);
        $this->assertNotNull($lesson->homework_auto_opened_at);
    }

    /** @test */
    public function course_outside_generic_pilot_is_not_opened(): void
    {
        $course = Course::factory()->create(['slug' => 'other-course']);
        $lesson = Lesson::factory()->for($course)->create();

        $lesson->update(['youtube_url' => 'https://youtu.be/other']);

        $lesson->refresh();
        $this->assertFalse((bool) $lesson->homework_enabled);
        $this->assertNull($lesson->homework_auto_opened_at);
        $this->assertNotNull($lesson->recording_attached_at, 'Штамп записи всё равно ставится.');
    }

    /** @test */
    public function empty_generic_slugs_means_feature_sleeps(): void
    {
        config(['homework.auto_open.generic_course_slugs' => []]);

        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $lesson = Lesson::factory()->for($course)->create();

        $lesson->update(['youtube_url' => 'https://youtu.be/asleep']);

        $this->assertFalse((bool) $lesson->fresh()->homework_enabled);
        $this->assertNull($lesson->fresh()->homework_auto_opened_at);
    }

    /** @test */
    public function manual_homework_enabled_is_not_hijacked(): void
    {
        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Уже включено вручную',
            'homework_auto_opened_at' => null,
        ]);

        $lesson->update(['youtube_url' => 'https://youtu.be/manual']);

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled);
        $this->assertSame('Уже включено вручную', $lesson->homework_prompt);
        $this->assertNull($lesson->homework_auto_opened_at, 'Ручное ДЗ не должно получить маркер автомата.');
        $this->assertCount(0, Http::recorded(), 'Не должны пушить при уже включённом ДЗ.');
    }

    /** @test */
    public function generic_immediate_kill_switch_disables_open(): void
    {
        config(['homework.auto_open.generic_immediate' => false]);

        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $lesson = Lesson::factory()->for($course)->create();

        $lesson->update(['youtube_url' => 'https://youtu.be/killed']);

        $this->assertFalse((bool) $lesson->fresh()->homework_enabled);
        $this->assertNull($lesson->fresh()->homework_auto_opened_at);
    }

    /** @test */
    public function custom_generic_prompt_from_config_is_used(): void
    {
        config(['homework.auto_open.generic_prompt' => 'Сдайте ДЗ в кабинете']);

        $course = Course::factory()->create(['slug' => 'hindi-pilot']);
        $lesson = Lesson::factory()->for($course)->create();

        $lesson->update(['youtube_url' => 'https://youtu.be/custom-prompt']);

        $this->assertSame('Сдайте ДЗ в кабинете', $lesson->fresh()->homework_prompt);
    }
}
