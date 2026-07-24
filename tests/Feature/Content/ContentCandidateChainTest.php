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
 * End-to-end Wave 1 → Wave 2 wiring through ContentCandidateObserver: marking
 * a clip free auto-drafts a social post; accepting that draft dispatches the
 * publish job. Isolated from the actual n8n HTTP call (Bus::fake) — that
 * path is covered by PublishSocialPostJobTest.
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
            'title' => 'Клип про алфавит',
        ]);

        // Wave 1: mark_free-equivalent — flips is_free, ContentCandidateSync
        // mirrors it to status=accepted on the clip candidate.
        $clip->update(['is_free' => true]);

        $clipCandidate = ContentCandidate::where('lecture_clip_id', $clip->id)->firstOrFail();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $clipCandidate->status);

        // Wave 2: ContentCandidateObserver should have auto-drafted a social post.
        $socialDraft = ContentCandidate::where('parent_id', $clipCandidate->id)
            ->where('type', ContentCandidate::TYPE_SOCIAL_POST)
            ->first();
        $this->assertNotNull($socialDraft);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $socialDraft->status);

        Bus::assertNotDispatched(PublishSocialPostJob::class);

        // Staff accepts the draft in Filament (the "accept" table action).
        $socialDraft->update(['status' => ContentCandidate::STATUS_ACCEPTED]);

        Bus::assertDispatched(PublishSocialPostJob::class, fn ($job) => $job->contentCandidateId === $socialDraft->id);
    }

    public function test_accepting_email_blast_dispatches_mail_job(): void
    {
        Bus::fake();

        $digest = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_EMAIL_BLAST,
            'status' => ContentCandidate::STATUS_DRAFT,
            'title' => 'Дайджест недели',
            'body' => 'Текст дайджеста',
        ]);

        Bus::assertNotDispatched(SendContentOneShotMailJob::class);

        $digest->update(['status' => ContentCandidate::STATUS_ACCEPTED]);

        Bus::assertDispatched(SendContentOneShotMailJob::class, fn ($job) => $job->contentCandidateId === $digest->id);
    }
}
