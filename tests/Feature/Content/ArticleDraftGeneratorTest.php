<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\ArticleDraftGenerator;
use App\Services\Content\QuotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ArticleDraftGenerator (H1550, Wave 4). No OPENROUTER_API_KEY → heuristic path.
 * VERIFICATION D1 + D2: lesson(s) → article draft; body not a full transcript dump.
 */
class ArticleDraftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => null]);
    }

    private function writeLongTranscript(string $path): string
    {
        $words = [];
        $t = 0.0;
        // ~40 short sentences — enough to measure ratio / dump guard.
        for ($s = 0; $s < 40; $s++) {
            $words[] = ['word' => 'Это', 'punctuated_word' => 'Это', 'start' => $t, 'end' => $t + 0.2];
            $t += 0.2;
            $words[] = ['word' => 'предложение', 'punctuated_word' => 'предложение', 'start' => $t, 'end' => $t + 0.3];
            $t += 0.3;
            $words[] = ['word' => 'номер', 'punctuated_word' => 'номер', 'start' => $t, 'end' => $t + 0.2];
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

        // Approximate source length for ratio checks (joined sentence text).
        return str_repeat('Это предложение номер X. ', 40);
    }

    private function lessonWithTranscript(): Lesson
    {
        Storage::fake('public');
        $path = 'transcripts/article-long.json';
        $this->writeLongTranscript($path);

        return Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => $path,
            'topic' => 'Санскритский алфавит',
            'title' => 'Лекция 3',
            'is_published' => true,
        ]);
    }

    public function test_lesson_yields_article_draft(): void
    {
        $lesson = $this->lessonWithTranscript();

        $article = app(ArticleDraftGenerator::class)->draftForLesson($lesson);

        $this->assertNotNull($article);
        $this->assertSame(ContentCandidate::TYPE_ARTICLE, $article->type);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $article->status);
        $this->assertSame('blog', $article->channel_target);
        $this->assertSame($lesson->id, $article->lesson_id);
        $this->assertNotSame('', trim((string) $article->body));
        $this->assertStringContainsString('#', (string) $article->body);
        $this->assertSame('lecture_transcript', $article->meta['source'] ?? null);
        $this->assertSame('lesson', $article->meta['pack'] ?? null);
    }

    public function test_body_is_not_full_transcript_dump(): void
    {
        $lesson = $this->lessonWithTranscript();
        $article = app(ArticleDraftGenerator::class)->draftForLesson($lesson);
        $this->assertNotNull($article);

        $bodyLen = mb_strlen((string) $article->body);
        $sourceLen = (int) ($article->meta['source_chars'] ?? 0);

        $this->assertGreaterThan(0, $sourceLen);
        $this->assertLessThanOrEqual(ArticleDraftGenerator::MAX_BODY_CHARS, $bodyLen);
        $this->assertLessThanOrEqual(
            (int) floor($sourceLen * ArticleDraftGenerator::MAX_BODY_RATIO) + 5,
            $bodyLen,
            'Body must stay under MAX_BODY_RATIO of source transcript length'
        );
        // Absolute dump guard: body must be substantially shorter than a 40-sentence dump.
        $this->assertLessThan($sourceLen * 0.5, $bodyLen);
    }

    public function test_quote_passes_quote_policy(): void
    {
        $lesson = $this->lessonWithTranscript();
        $article = app(ArticleDraftGenerator::class)->draftForLesson($lesson);
        $this->assertNotNull($article);

        if ($article->quote !== null) {
            QuotePolicy::assertPublicQuote($article->quote);
            $this->assertLessThanOrEqual(2, QuotePolicy::sentenceCount($article->quote));
        }
    }

    public function test_idempotent_regenerate_does_not_duplicate(): void
    {
        $lesson = $this->lessonWithTranscript();
        $gen = app(ArticleDraftGenerator::class);

        $first = $gen->draftForLesson($lesson);
        $second = $gen->draftForLesson($lesson);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            ContentCandidate::where('lesson_id', $lesson->id)
                ->where('type', ContentCandidate::TYPE_ARTICLE)
                ->count()
        );
    }

    public function test_does_not_overwrite_accepted_article(): void
    {
        $lesson = $this->lessonWithTranscript();
        $gen = app(ArticleDraftGenerator::class);
        $article = $gen->draftForLesson($lesson);
        $this->assertNotNull($article);

        $article->update([
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'body' => 'Staff-locked body',
        ]);

        $again = $gen->draftForLesson($lesson);
        $this->assertSame($article->id, $again->id);
        $this->assertSame('Staff-locked body', $again->fresh()->body);
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $again->fresh()->status);
    }

    public function test_weekly_pack_from_multiple_lessons(): void
    {
        Storage::fake('public');
        $course = Course::factory()->create();
        $lessons = [];
        for ($i = 0; $i < 2; $i++) {
            $path = "transcripts/article-week-{$i}.json";
            $this->writeLongTranscript($path);
            $lessons[] = Lesson::factory()->for($course)->create([
                'transcript_file' => $path,
                'topic' => "Тема {$i}",
                'title' => "Лекция {$i}",
                'is_published' => true,
            ]);
        }

        $pack = app(ArticleDraftGenerator::class)->composeWeeklyPack();
        $this->assertNotNull($pack);
        $this->assertSame(ContentCandidate::TYPE_ARTICLE, $pack->type);
        $this->assertSame('newsletter_body', $pack->channel_target);
        $this->assertSame('weekly', $pack->meta['pack'] ?? null);
        $this->assertCount(2, $pack->meta['lesson_ids'] ?? []);
        $this->assertNotSame('', trim((string) $pack->body));
        $this->assertLessThanOrEqual(ArticleDraftGenerator::MAX_BODY_CHARS, mb_strlen((string) $pack->body));
    }

    public function test_empty_transcript_returns_null(): void
    {
        Storage::fake('public');
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => null,
        ]);

        $this->assertNull(app(ArticleDraftGenerator::class)->draftForLesson($lesson));
    }

    public function test_to_markdown_export(): void
    {
        $lesson = $this->lessonWithTranscript();
        $article = app(ArticleDraftGenerator::class)->draftForLesson($lesson);
        $this->assertNotNull($article);

        $md = app(ArticleDraftGenerator::class)->toMarkdown($article);
        $this->assertStringContainsString((string) $article->title, $md);
        $this->assertNotSame('', trim($md));
    }
}
