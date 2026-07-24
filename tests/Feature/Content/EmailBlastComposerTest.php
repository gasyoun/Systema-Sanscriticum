<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\EmailBlastComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmailBlastComposerTest extends TestCase
{
    use RefreshDatabase;

    private function acceptedClip(Lesson $lesson, string $title, ?Carbon $createdAt = null): ContentCandidate
    {
        $candidate = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_CLIP,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'lesson_id' => $lesson->id,
            'title' => $title,
        ]);

        if ($createdAt !== null) {
            $candidate->forceFill(['created_at' => $createdAt])->save();
        }

        return $candidate;
    }

    public function test_composes_digest_from_accepted_clips_in_last_7_days(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create(['title' => 'Лекция про глаголы']);
        $this->acceptedClip($lesson, 'Фрагмент А', now()->subDays(2));
        $this->acceptedClip($lesson, 'Фрагмент Б', now()->subDays(5));

        $digest = app(EmailBlastComposer::class)->composeWeekly();

        $this->assertNotNull($digest);
        $this->assertSame(ContentCandidate::TYPE_EMAIL_BLAST, $digest->type);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $digest->status);
        $this->assertSame('email', $digest->channel_target);
        $this->assertStringContainsString('Фрагмент А', $digest->body);
        $this->assertStringContainsString('Фрагмент Б', $digest->body);
        $this->assertCount(2, $digest->meta['clip_candidate_ids']);
    }

    public function test_excludes_clips_older_than_7_days(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $this->acceptedClip($lesson, 'Свежий', now()->subDays(1));
        $this->acceptedClip($lesson, 'Старый', now()->subDays(10));

        $digest = app(EmailBlastComposer::class)->composeWeekly();

        $this->assertStringContainsString('Свежий', $digest->body);
        $this->assertStringNotContainsString('Старый', $digest->body);
    }

    public function test_excludes_draft_and_discarded_clips(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        ContentCandidate::create([
            'type' => ContentCandidate::TYPE_CLIP,
            'status' => ContentCandidate::STATUS_DRAFT,
            'lesson_id' => $lesson->id,
            'title' => 'Ещё не принят',
        ]);

        $this->assertNull(app(EmailBlastComposer::class)->composeWeekly());
    }

    public function test_rerunning_same_week_updates_in_place_not_duplicates(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $this->acceptedClip($lesson, 'Первый');

        $first = app(EmailBlastComposer::class)->composeWeekly();
        $this->acceptedClip($lesson, 'Второй');
        $second = app(EmailBlastComposer::class)->composeWeekly();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContentCandidate::where('type', ContentCandidate::TYPE_EMAIL_BLAST)->count());
        $this->assertStringContainsString('Второй', $second->fresh()->body);
    }
}
