<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LectureClip -> ContentCandidate mirror (H1547 W1, IMPLEMENTATION step 7).
 * Runs via LectureClipObserver regardless of feature flags — cheap idempotent
 * upsert, not a publish action.
 */
class ContentCandidateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create();
    }

    public function test_creating_a_clip_creates_a_draft_candidate(): void
    {
        $lesson = $this->lesson();

        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Фрагмент 1',
        ]);

        $candidate = ContentCandidate::where('lecture_clip_id', $clip->id)->firstOrFail();

        $this->assertSame(ContentCandidate::TYPE_CLIP, $candidate->type);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $candidate->status);
        $this->assertSame($lesson->id, $candidate->lesson_id);
        $this->assertSame('Фрагмент 1', $candidate->title);
        $this->assertNull($candidate->channel_target);
    }

    public function test_marking_clip_free_flips_candidate_to_accepted(): void
    {
        $lesson = $this->lesson();
        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Фрагмент 1',
        ]);

        $clip->update(['is_free' => true]);

        $candidate = ContentCandidate::where('lecture_clip_id', $clip->id)->firstOrFail();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $candidate->status);
        $this->assertSame('vk_wall', $candidate->channel_target);
    }

    public function test_sync_is_idempotent_one_candidate_per_clip(): void
    {
        $lesson = $this->lesson();
        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Фрагмент 1',
        ]);

        $clip->update(['title' => 'Фрагмент 1 (правка)']);
        $clip->update(['title' => 'Фрагмент 1 (ещё правка)']);

        $this->assertSame(1, ContentCandidate::where('lecture_clip_id', $clip->id)->count());
        $this->assertSame(
            'Фрагмент 1 (ещё правка)',
            ContentCandidate::where('lecture_clip_id', $clip->id)->first()->title
        );
    }
}
