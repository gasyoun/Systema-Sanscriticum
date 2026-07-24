<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LessonObserver: publish-on-lesson-publish trigger (H1547 W1, IMPLEMENTATION
 * step 6). Both `content_from_lectures` AND `clip_marketing` gate dispatch —
 * both OFF by default, so this is prod-inert until explicit activation.
 */
class LessonPublishTriggerTest extends TestCase
{
    use RefreshDatabase;

    private function writeTranscript(string $path): void
    {
        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'Начало', 'punctuated_word' => 'Начало.', 'start' => 0.0, 'end' => 1.0],
                            ['word' => 'Конец', 'punctuated_word' => 'Конец.', 'start' => 100.0, 'end' => 101.0],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function draftLesson(array $overrides = []): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create(array_merge([
            'is_published' => false,
        ], $overrides));
    }

    public function test_both_flags_off_no_dispatch_on_publish(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['features.content_from_lectures' => false, 'features.clip_marketing' => false]);

        $path = 'transcripts/trigger-off.json';
        $this->writeTranscript($path);
        $lesson = $this->draftLesson(['transcript_file' => $path, 'video_url' => 'https://example.com/v.mp4']);

        $lesson->update(['is_published' => true]);

        Bus::assertNotDispatched(DispatchLectureClipExtractionJob::class);
    }

    public function test_both_flags_on_dispatches_once_on_publish_transition(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['features.content_from_lectures' => true, 'features.clip_marketing' => true]);

        $path = 'transcripts/trigger-on.json';
        $this->writeTranscript($path);
        $lesson = $this->draftLesson(['transcript_file' => $path, 'video_url' => 'https://example.com/v.mp4']);

        $lesson->update(['is_published' => true]);

        Bus::assertDispatched(DispatchLectureClipExtractionJob::class, fn ($job) => $job->lessonId === $lesson->id);
    }

    public function test_unrelated_update_after_publish_does_not_redispatch(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['features.content_from_lectures' => true, 'features.clip_marketing' => true]);

        $path = 'transcripts/trigger-idempotent.json';
        $this->writeTranscript($path);
        $lesson = $this->draftLesson(['transcript_file' => $path, 'video_url' => 'https://example.com/v.mp4']);

        $lesson->update(['is_published' => true]);
        $lesson->update(['title' => 'Изменённое название']);

        Bus::assertDispatchedTimes(DispatchLectureClipExtractionJob::class, 1);
    }

    public function test_no_dispatch_if_clips_already_exist(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['features.content_from_lectures' => true, 'features.clip_marketing' => true]);

        $path = 'transcripts/trigger-existing-clips.json';
        $this->writeTranscript($path);
        $lesson = $this->draftLesson(['transcript_file' => $path, 'video_url' => 'https://example.com/v.mp4']);

        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Уже нарезано вручную',
        ]);

        $lesson->update(['is_published' => true]);

        Bus::assertNotDispatched(DispatchLectureClipExtractionJob::class);
    }

    public function test_no_dispatch_without_transcript(): void
    {
        Bus::fake();
        config(['features.content_from_lectures' => true, 'features.clip_marketing' => true]);

        $lesson = $this->draftLesson(['transcript_file' => null, 'video_url' => 'https://example.com/v.mp4']);
        $lesson->update(['is_published' => true]);

        Bus::assertNotDispatched(DispatchLectureClipExtractionJob::class);
    }
}
