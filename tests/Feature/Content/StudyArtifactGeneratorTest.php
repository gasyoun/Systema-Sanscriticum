<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\PublishSocialPostJob;
use App\Jobs\SendContentOneShotMailJob;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\QuotePolicy;
use App\Services\Content\StudyArtifactGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * StudyArtifactGenerator (H1551, Wave 5). No OPENROUTER_API_KEY → heuristic path.
 * VERIFICATION E1 + E2: study_artifact drafts; not selected by pilot publish jobs.
 */
class StudyArtifactGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => null]);
    }

    private function writeLongTranscript(string $path): void
    {
        $words = [];
        $t = 0.0;
        for ($s = 0; $s < 36; $s++) {
            $words[] = ['word' => 'Это', 'punctuated_word' => 'Это', 'start' => $t, 'end' => $t + 0.2];
            $t += 0.2;
            $words[] = ['word' => 'фрагмент', 'punctuated_word' => 'фрагмент', 'start' => $t, 'end' => $t + 0.3];
            $t += 0.3;
            $words[] = ['word' => 'лекции', 'punctuated_word' => 'лекции', 'start' => $t, 'end' => $t + 0.2];
            $t += 0.2;
            $words[] = ['word' => (string) ($s + 1), 'punctuated_word' => ($s + 1).'.', 'start' => $t, 'end' => $t + 0.2];
            $t += 2.0;
        }

        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => $words,
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function lessonWithTranscript(): Lesson
    {
        Storage::fake('public');
        $path = 'transcripts/study-long.json';
        $this->writeLongTranscript($path);

        return Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => $path,
            'topic' => 'Склонение существительных',
            'title' => 'Лекция 7',
            'is_published' => true,
        ]);
    }

    public function test_lesson_yields_three_study_artifact_drafts(): void
    {
        $lesson = $this->lessonWithTranscript();

        $created = app(StudyArtifactGenerator::class)->draftForLesson($lesson);

        $this->assertCount(3, $created);
        $kinds = collect($created)->map(fn (ContentCandidate $c) => $c->meta['kind'] ?? null)->sort()->values()->all();
        $this->assertSame([
            StudyArtifactGenerator::KIND_CARD_SEEDS,
            StudyArtifactGenerator::KIND_HOMEWORK,
            StudyArtifactGenerator::KIND_SUMMARY,
        ], $kinds);

        foreach ($created as $row) {
            $this->assertSame(ContentCandidate::TYPE_STUDY_ARTIFACT, $row->type);
            $this->assertSame(ContentCandidate::STATUS_DRAFT, $row->status);
            $this->assertSame(StudyArtifactGenerator::CHANNEL_STAFF_STUDY, $row->channel_target);
            $this->assertSame($lesson->id, $row->lesson_id);
            $this->assertNotSame('', trim((string) $row->body));
            $this->assertSame('lecture_transcript', $row->meta['source'] ?? null);
            $this->assertFalse($row->meta['pilot_publish'] ?? true);
            $this->assertLessThanOrEqual(
                StudyArtifactGenerator::MAX_BODY_CHARS,
                mb_strlen((string) $row->body)
            );
        }

        $this->assertSame(
            3,
            ContentCandidate::where('lesson_id', $lesson->id)
                ->where('type', ContentCandidate::TYPE_STUDY_ARTIFACT)
                ->count()
        );
    }

    public function test_body_is_not_full_transcript_dump(): void
    {
        $lesson = $this->lessonWithTranscript();
        $created = app(StudyArtifactGenerator::class)->draftForLesson($lesson);
        $this->assertNotEmpty($created);

        foreach ($created as $row) {
            $bodyLen = mb_strlen((string) $row->body);
            $sourceLen = (int) ($row->meta['source_chars'] ?? 0);
            $this->assertGreaterThan(0, $sourceLen);
            $this->assertLessThanOrEqual(
                (int) floor($sourceLen * StudyArtifactGenerator::MAX_BODY_RATIO) + 5,
                $bodyLen
            );
            $this->assertLessThan($sourceLen * 0.5, $bodyLen);
        }
    }

    public function test_summary_quote_passes_quote_policy(): void
    {
        $lesson = $this->lessonWithTranscript();
        $created = app(StudyArtifactGenerator::class)->draftForLesson($lesson);
        $summary = collect($created)->first(
            fn (ContentCandidate $c) => ($c->meta['kind'] ?? null) === StudyArtifactGenerator::KIND_SUMMARY
        );
        $this->assertNotNull($summary);

        if ($summary->quote !== null) {
            QuotePolicy::assertPublicQuote($summary->quote);
            $this->assertLessThanOrEqual(2, QuotePolicy::sentenceCount($summary->quote));
        }
    }

    public function test_idempotent_regenerate_does_not_duplicate(): void
    {
        $lesson = $this->lessonWithTranscript();
        $gen = app(StudyArtifactGenerator::class);

        $first = $gen->draftForLesson($lesson);
        $second = $gen->draftForLesson($lesson);

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertSame(
            collect($first)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(
            3,
            ContentCandidate::where('lesson_id', $lesson->id)
                ->where('type', ContentCandidate::TYPE_STUDY_ARTIFACT)
                ->count()
        );
    }

    public function test_does_not_overwrite_accepted_study_artifact(): void
    {
        $lesson = $this->lessonWithTranscript();
        $gen = app(StudyArtifactGenerator::class);
        $created = $gen->draftForLesson($lesson);
        $summary = collect($created)->first(
            fn (ContentCandidate $c) => ($c->meta['kind'] ?? null) === StudyArtifactGenerator::KIND_SUMMARY
        );
        $this->assertNotNull($summary);

        $summary->update([
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'body' => 'Staff-locked study summary',
        ]);

        $again = $gen->draftForLesson($lesson);
        $summaryAgain = collect($again)->first(
            fn (ContentCandidate $c) => ($c->meta['kind'] ?? null) === StudyArtifactGenerator::KIND_SUMMARY
        );
        $this->assertNotNull($summaryAgain);
        $this->assertSame($summary->id, $summaryAgain->id);
        $this->assertSame('Staff-locked study summary', $summaryAgain->fresh()->body);
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $summaryAgain->fresh()->status);
    }

    public function test_accepting_study_artifact_does_not_dispatch_pilot_jobs(): void
    {
        Bus::fake();

        $lesson = $this->lessonWithTranscript();
        $created = app(StudyArtifactGenerator::class)->draftForLesson($lesson);
        $this->assertNotEmpty($created);

        foreach ($created as $row) {
            $row->update(['status' => ContentCandidate::STATUS_ACCEPTED]);
        }

        Bus::assertNotDispatched(PublishSocialPostJob::class);
        Bus::assertNotDispatched(SendContentOneShotMailJob::class);

        foreach ($created as $row) {
            $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $row->fresh()->status);
            $this->assertSame(ContentCandidate::TYPE_STUDY_ARTIFACT, $row->fresh()->type);
        }
    }

    public function test_pilot_publish_job_ignores_study_artifact_type(): void
    {
        config(['features.content_auto_publish_pilot' => true]);

        $lesson = $this->lessonWithTranscript();
        $study = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_STUDY_ARTIFACT,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'lesson_id' => $lesson->id,
            'title' => 'Should not publish',
            'body' => 'Staff study only',
            'channel_target' => StudyArtifactGenerator::CHANNEL_STAFF_STUDY,
            'meta' => ['kind' => StudyArtifactGenerator::KIND_SUMMARY, 'pilot_publish' => false],
        ]);

        // Direct handle: type guard must no-op (no exception, no status flip to published).
        (new PublishSocialPostJob($study->id))->handle();

        $study->refresh();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $study->status);
        $this->assertNull($study->published_at);
    }

    public function test_empty_transcript_returns_empty(): void
    {
        Storage::fake('public');
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => null,
        ]);

        $this->assertSame([], app(StudyArtifactGenerator::class)->draftForLesson($lesson));
    }
}
