<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\PublishSocialPostJob;
use App\Jobs\SendContentOneShotMailJob;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * End-to-end Wave 1 → Wave 2/3 wiring through ContentCandidateObserver.
 * Isolated from the actual n8n HTTP call (Bus::fake).
 */
class ContentCandidateChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_marking_clip_free_then_accepting_draft_dispatches_publish_job(): void
    {
        Bus::fake();

        $lesson = Lesson::factory()->for(Course::factory())->create();
        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Фрагмент про алфавит',
        ]);

        $clip->update(['is_free' => true]);

        $clipCandidate = ContentCandidate::where('lecture_clip_id', $clip->id)->firstOrFail();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $clipCandidate->status);

        $socialDraft = ContentCandidate::where('parent_id', $clipCandidate->id)
            ->where('type', ContentCandidate::TYPE_SOCIAL_POST)
            ->first();
        $this->assertNotNull($socialDraft);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $socialDraft->status);

        Bus::assertNotDispatched(PublishSocialPostJob::class);

        $socialDraft->update(['status' => ContentCandidate::STATUS_ACCEPTED]);

        Bus::assertDispatched(PublishSocialPostJob::class, fn ($job) => $job->contentCandidateId === $socialDraft->id);
    }

    public function test_accepting_email_blast_dispatches_mail_job(): void
    {
        Bus::fake();

        $digest = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_EMAIL_BLAST,
            'status' => ContentCandidate::STATUS_DRAFT,
            'title' => 'Недельный дайджест',
            'body' => 'Текст дайджеста',
        ]);

        Bus::assertNotDispatched(SendContentOneShotMailJob::class);

        $digest->update(['status' => ContentCandidate::STATUS_ACCEPTED]);

        Bus::assertDispatched(SendContentOneShotMailJob::class, fn ($job) => $job->contentCandidateId === $digest->id);
    }

    public function test_accepting_faq_draft_is_editorial_noop_after_band_retirement(): void
    {
        $path = storage_path('framework/testing/faq_chain_'.uniqid('', true).'.md');
        // The retired config key must not resurrect the band if an old env sets it.
        config(['content.lecture_faq_path' => $path]);

        try {
            $draft = ContentCandidate::create([
                'type' => ContentCandidate::TYPE_FAQ_DRAFT,
                'status' => ContentCandidate::STATUS_DRAFT,
                'title' => 'Что такое висарга?',
                'body' => 'Это h на конце слова.',
                'channel_target' => 'cabinet_faq',
            ]);

            $draft->update(['status' => ContentCandidate::STATUS_ACCEPTED]);
            $draft->refresh();

            // RAG grill card 6 (17-10-2026): the lecture FAQ band is retired —
            // Accept is editorial only and writes no knowledge file.
            $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $draft->status);
            $this->assertFileDoesNotExist($path);
            $this->assertDatabaseCount('content_candidates', 1);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
