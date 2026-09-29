<?php

declare(strict_types=1);

namespace Tests\Feature\Transcripts;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Конвейер распознавания: очередь уроков и тихая приёмка стенограммы.
 *
 * Про клипы. LessonObserver шлёт лекцию в нарезку ТОЛЬКО при переходе урока в
 * опубликованные (и при создании), а не при появлении стенограммы, — поэтому
 * массовая заливка на опубликованные уроки лавины клипов не вызывает. Это
 * закреплено тестом ниже, чтобы условие не расширили молча.
 *
 * quiet=1 нужен для другого: массовая заливка не должна поднимать обработчики
 * модели вообще (сейчас они делают проверки впустую, завтра к ним добавят ещё
 * что-нибудь). Одиночная загрузка из n8n идёт как прежде — с событиями.
 */
class TranscriptIngestPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'lesson-sync-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.lesson_sync.secret' => self::SECRET,
            // Побочные действия наблюдателя включены — как на проде 23-09-2026.
            'features.content_from_lectures' => true,
            'features.clip_marketing' => true,
        ]);
    }

    public function test_queue_lists_only_lessons_with_a_recording_and_no_transcript(): void
    {
        $gasuns = Teacher::create(['name' => 'Гасунс Марцис Юрьевич']);
        $other = Teacher::create(['name' => 'Другой преподаватель']);
        $grammar = $this->course('Грамматика гр.51', $gasuns);
        $foreign = $this->course('Чужой курс', $other);

        $needed = $this->lesson($grammar, 'Урок 1', rutube: 'https://rutube.ru/video/aaa/');
        $onlyYoutube = $this->lesson($grammar, 'Урок 2', youtube: 'https://youtu.be/bbb');
        $this->lesson($grammar, 'Уже со стенограммой', rutube: 'https://rutube.ru/video/ccc/', transcript: 'transcripts/lesson-x.json');
        $this->lesson($grammar, 'Без записи');
        $this->lesson($foreign, 'Чужой урок', rutube: 'https://rutube.ru/video/ddd/');

        $this->assertSame(0, Artisan::call('lessons:transcript-queue', ['--teacher' => $gasuns->id]));

        $rows = json_decode(Artisan::output(), true);
        $this->assertSame([$needed->id, $onlyYoutube->id], array_column($rows, 'lesson_id'));
        $this->assertSame('https://rutube.ru/video/aaa/', $rows[0]['rutube_url']);
        $this->assertNull($rows[0]['youtube_url']);
        $this->assertNull($rows[1]['rutube_url']);
        $this->assertSame('Грамматика гр.51', $rows[0]['course']);
    }

    public function test_quiet_upload_attaches_the_transcript_without_model_events(): void
    {
        $lesson = $this->publishedLessonWithVideo();
        Event::fake(['eloquent.updated: '.Lesson::class]);

        $response = $this->postJson(
            "/api/lessons/{$lesson->id}/transcript",
            ['transcript' => $this->deepgram(), 'quiet' => 1],
            ['X-Secret-Key' => self::SECRET],
        );

        $response->assertOk()->assertJson(['status' => 'success', 'sentences' => 2, 'quiet' => true]);
        $this->assertSame('transcripts/lesson-'.$lesson->id.'.json', $lesson->fresh()->transcript_file);
        Storage::disk('local')->assertExists('transcripts/lesson-'.$lesson->id.'.json');
        Event::assertNotDispatched('eloquent.updated: '.Lesson::class);
    }

    public function test_normal_upload_fires_model_events_as_before(): void
    {
        $lesson = $this->publishedLessonWithVideo();
        Event::fake(['eloquent.updated: '.Lesson::class]);

        $this->postJson(
            "/api/lessons/{$lesson->id}/transcript",
            ['transcript' => $this->deepgram()],
            ['X-Secret-Key' => self::SECRET],
        )->assertOk()->assertJson(['quiet' => false]);

        Event::assertDispatched('eloquent.updated: '.Lesson::class);
    }

    public function test_attaching_a_transcript_does_not_start_clip_cutting_publishing_does(): void
    {
        Bus::fake();
        $lesson = $this->publishedLessonWithVideo();

        $this->postJson(
            "/api/lessons/{$lesson->id}/transcript",
            ['transcript' => $this->deepgram()],
            ['X-Secret-Key' => self::SECRET],
        )->assertOk();

        // Стенограмма сама по себе нарезку не запускает: наблюдатель смотрит на
        // переход в опубликованные.
        Bus::assertNotDispatched(DispatchLectureClipExtractionJob::class);

        $lesson->fresh()->forceFill(['is_published' => false])->save();
        Bus::assertNotDispatched(DispatchLectureClipExtractionJob::class);

        $lesson->fresh()->forceFill(['is_published' => true])->save();
        Bus::assertDispatched(DispatchLectureClipExtractionJob::class);
    }

    public function test_upload_without_words_is_refused_even_when_quiet(): void
    {
        $lesson = $this->publishedLessonWithVideo();

        $this->postJson(
            "/api/lessons/{$lesson->id}/transcript",
            ['transcript' => ['results' => ['channels' => [['alternatives' => [['words' => []]]]]]], 'quiet' => 1],
            ['X-Secret-Key' => self::SECRET],
        )->assertStatus(422);

        $this->assertNull($lesson->fresh()->transcript_file);
    }

    /** @return array<string, mixed> */
    private function deepgram(): array
    {
        $words = [
            ['word' => 'мы', 'punctuated_word' => 'Мы', 'start' => 0.1, 'end' => 0.4, 'confidence' => 0.99],
            ['word' => 'начинаем', 'punctuated_word' => 'начинаем.', 'start' => 0.4, 'end' => 1.2, 'confidence' => 0.98],
            ['word' => 'урок', 'punctuated_word' => 'Урок', 'start' => 1.4, 'end' => 1.9, 'confidence' => 0.97],
            ['word' => 'первый', 'punctuated_word' => 'первый.', 'start' => 1.9, 'end' => 2.6, 'confidence' => 0.96],
        ];

        return ['results' => ['channels' => [['alternatives' => [['transcript' => 'Мы начинаем. Урок первый.', 'words' => $words]]]]]];
    }

    private function publishedLessonWithVideo(): Lesson
    {
        $teacher = Teacher::create(['name' => 'Гасунс Марцис Юрьевич']);

        return $this->lesson($this->course('Грамматика гр.53', $teacher), 'Урок', rutube: 'https://rutube.ru/video/eee/', published: true);
    }

    private function course(string $title, Teacher $teacher): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10),
            'teacher_id' => $teacher->id,
        ]);
    }

    private function lesson(
        Course $course,
        string $title,
        ?string $rutube = null,
        ?string $youtube = null,
        ?string $transcript = null,
        bool $published = false,
    ): Lesson {
        return Lesson::create([
            'course_id' => $course->id,
            'title' => $title,
            'lesson_date' => '2026-09-01',
            'rutube_url' => $rutube,
            'youtube_url' => $youtube,
            'transcript_file' => $transcript,
            'is_published' => $published,
        ]);
    }
}
