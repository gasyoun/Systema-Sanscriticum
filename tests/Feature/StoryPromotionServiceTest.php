<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\StoryPost;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Stories\StoryPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * StoryPromotionService (H3964, юнит 5): медиа ПРИНЯТОЙ работы копируется в
 * стор telegram-story и заводится черновиком persona-полосы; повтор и
 * не-медиа/не-принятое — громкий отказ. Публикация строки заперта визой
 * отдельно (StoriesPublishStoryTest::student_media_without_visa_*).
 */
class StoryPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private StoryPromotionService $service;

    private HomeworkSubmission $submission;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StoryPromotionService;
        $this->workDir = storage_path('app/testing/h3964/promotion');
        File::deleteDirectory($this->workDir);
        File::ensureDirectoryExists($this->workDir);

        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'stories-promo@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        $this->submission = HomeworkSubmission::create([
            'user_id' => User::factory()->create()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_ACCEPTED,
            'last_activity_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    private function attachFile(string $name, string $mime): HomeworkFile
    {
        $storagePath = 'homework/'.$name;
        $absolute = storage_path('app/'.$storagePath);
        File::ensureDirectoryExists(dirname($absolute));
        file_put_contents($absolute, 'media-bytes');

        $comment = HomeworkComment::create([
            'submission_id' => $this->submission->id,
            'author_id' => $this->submission->user_id,
            'author_role' => HomeworkComment::ROLE_STUDENT,
            'body' => 'Моя работа',
        ]);

        return HomeworkFile::create([
            'comment_id' => $comment->id,
            'disk' => 'local',
            'path' => $storagePath,
            'original_name' => $name,
            'size' => filesize($absolute),
            'mime' => $mime,
        ]);
    }

    /** @test */
    public function promotes_an_accepted_homework_photo_into_a_persona_draft(): void
    {
        $file = $this->attachFile('devanagari-work.jpg', 'image/jpeg');

        $post = $this->service->fromHomeworkFile($file, 'Работа ученика', null);

        $this->assertSame(StoryPost::KIND_PHOTO, $post->kind);
        $this->assertSame(StoryPost::LANE_PERSONA, $post->lane);
        $this->assertSame(StoryPost::SOURCE_HOMEWORK, $post->source);
        $this->assertSame(StoryPost::STATUS_DRAFT, $post->status, 'черновик: approve — куратор, публикация — издатель');
        $this->assertSame('homework-file-'.$file->id, $post->source_key);
        $this->assertFileExists($post->media_path, 'файл скопирован в стор telegram-story');
        $this->assertStringContainsString('telegram-story/media', $post->media_path);
        $this->assertNotSame(storage_path('app/'.$file->path), $post->media_path, 'оригинал работы не переезжает');

        // Повтор того же файла — громкий отказ, дублей в очереди нет.
        $this->expectException(RuntimeException::class);
        $this->service->fromHomeworkFile($file, 'ещё раз', null);
    }

    /** @test */
    public function video_mime_maps_to_the_video_kind(): void
    {
        $file = $this->attachFile('recitation.mp4', 'video/mp4');

        $post = $this->service->fromHomeworkFile($file, 'Чтение', null);

        $this->assertSame(StoryPost::KIND_VIDEO, $post->kind);
    }

    /** @test */
    public function non_media_files_are_refused(): void
    {
        $file = $this->attachFile('essay.pdf', 'application/pdf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не фото и не видео');

        $this->service->fromHomeworkFile($file, 'конспект', null);
    }

    /** @test */
    public function only_accepted_submissions_can_be_promoted(): void
    {
        $this->submission->forceFill(['status' => HomeworkSubmission::STATUS_SUBMITTED])->save();
        $file = $this->attachFile('draft.jpg', 'image/jpeg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('принятых работ');

        $this->service->fromHomeworkFile($file, 'ещё не принято', null);
    }
}
