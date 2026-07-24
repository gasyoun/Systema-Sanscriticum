<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\FaqDraftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FaqDraftGenerator (H1549, Wave 3). No OPENROUTER_API_KEY → heuristic path.
 * VERIFICATION C1 + C3: transcript fixture → ≥1 faq_draft; no SupportTopic seed.
 */
class FaqDraftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => null]);
    }

    private function writeTranscriptWithQuestions(string $path): void
    {
        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'Что', 'punctuated_word' => 'Что', 'start' => 10.0, 'end' => 10.3],
                            ['word' => 'такое', 'punctuated_word' => 'такое', 'start' => 10.3, 'end' => 10.7],
                            ['word' => 'санскрит', 'punctuated_word' => 'санскрит?', 'start' => 10.7, 'end' => 11.2],
                            ['word' => 'Это', 'punctuated_word' => 'Это', 'start' => 12.0, 'end' => 12.2],
                            ['word' => 'древний', 'punctuated_word' => 'древний', 'start' => 12.2, 'end' => 12.6],
                            ['word' => 'язык', 'punctuated_word' => 'язык.', 'start' => 12.6, 'end' => 13.0],
                            ['word' => 'Как', 'punctuated_word' => 'Как', 'start' => 40.0, 'end' => 40.2],
                            ['word' => 'устроен', 'punctuated_word' => 'устроен', 'start' => 40.2, 'end' => 40.6],
                            ['word' => 'алфавит', 'punctuated_word' => 'алфавит?', 'start' => 40.6, 'end' => 41.1],
                            ['word' => 'Он', 'punctuated_word' => 'Он', 'start' => 42.0, 'end' => 42.1],
                            ['word' => 'называется', 'punctuated_word' => 'называется', 'start' => 42.1, 'end' => 42.6],
                            ['word' => 'деванагари', 'punctuated_word' => 'деванагари.', 'start' => 42.6, 'end' => 43.2],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function lessonWithQuestions(): Lesson
    {
        Storage::fake('public');
        $path = 'transcripts/faq-questions.json';
        $this->writeTranscriptWithQuestions($path);

        return Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => $path,
            'topic' => 'Введение в санскрит',
            'title' => 'Лекция 1',
            'is_published' => true,
        ]);
    }

    public function test_transcript_fixture_yields_at_least_one_faq_draft(): void
    {
        $lesson = $this->lessonWithQuestions();

        $created = app(FaqDraftGenerator::class)->draftForLesson($lesson);

        $this->assertNotEmpty($created);
        $draft = $created[0];
        $this->assertSame(ContentCandidate::TYPE_FAQ_DRAFT, $draft->type);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $draft->status);
        $this->assertSame('cabinet_faq', $draft->channel_target);
        $this->assertSame($lesson->id, $draft->lesson_id);
        $this->assertStringContainsString('?', $draft->title);
        $this->assertNotSame('', trim((string) $draft->body));
        $this->assertLessThanOrEqual(FaqDraftGenerator::MAX_ANSWER_CHARS + 5, mb_strlen((string) $draft->body));
        $this->assertSame('lecture_transcript', $draft->meta['source'] ?? null);
    }

    public function test_no_support_topic_seed_required(): void
    {
        $lesson = $this->lessonWithQuestions();
        $created = app(FaqDraftGenerator::class)->draftForLesson($lesson);

        $this->assertNotEmpty($created);
        $this->assertSame(
            count($created),
            ContentCandidate::where('type', ContentCandidate::TYPE_FAQ_DRAFT)->count()
        );
        $this->assertDatabaseCount('content_candidates', count($created));
    }

    public function test_idempotent_regenerate_does_not_duplicate(): void
    {
        $lesson = $this->lessonWithQuestions();
        $gen = app(FaqDraftGenerator::class);

        $first = $gen->draftForLesson($lesson);
        $second = $gen->draftForLesson($lesson);

        $this->assertSame(count($first), count($second));
        $count = ContentCandidate::where('lesson_id', $lesson->id)
            ->where('type', ContentCandidate::TYPE_FAQ_DRAFT)
            ->count();
        $this->assertSame(count($first), $count);
        $this->assertLessThanOrEqual(FaqDraftGenerator::MAX_DRAFTS_PER_LESSON, $count);
        $titles = ContentCandidate::where('lesson_id', $lesson->id)
            ->where('type', ContentCandidate::TYPE_FAQ_DRAFT)
            ->pluck('title');
        $this->assertSame($titles->count(), $titles->unique()->count());
    }

    public function test_empty_transcript_returns_no_drafts(): void
    {
        Storage::fake('public');
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => null,
        ]);

        $this->assertSame([], app(FaqDraftGenerator::class)->draftForLesson($lesson));
    }
}
