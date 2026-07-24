<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\SocialDraftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SocialDraftGenerator (H1548, Wave 2). No OPENROUTER_API_KEY is configured
 * in the test env, so every assertion exercises the deterministic heuristic
 * template path — same idiom as SpanRanker's default-heuristic tests.
 */
class SocialDraftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => null]);
    }

    private function writeTranscript(string $path): void
    {
        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'Санскрит', 'punctuated_word' => 'Санскрит', 'start' => 10.0, 'end' => 10.5],
                            ['word' => 'древний', 'punctuated_word' => 'древний', 'start' => 10.5, 'end' => 11.0],
                            ['word' => 'язык', 'punctuated_word' => 'язык.', 'start' => 11.0, 'end' => 11.5],
                            ['word' => 'Вне', 'punctuated_word' => 'Вне', 'start' => 200.0, 'end' => 200.3],
                            ['word' => 'диапазона', 'punctuated_word' => 'диапазона.', 'start' => 200.3, 'end' => 200.8],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function acceptedClipCandidate(array $meta = []): ContentCandidate
    {
        Storage::fake('public');
        $path = 'transcripts/social-draft.json';
        $this->writeTranscript($path);

        $lesson = Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => $path,
            'topic' => 'Введение в санскрит',
            'title' => 'Лекция 1',
        ]);

        return ContentCandidate::create(array_merge([
            'type' => ContentCandidate::TYPE_CLIP,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'lesson_id' => $lesson->id,
            'title' => 'Фрагмент про алфавит',
            'meta' => ['start_seconds' => 0, 'end_seconds' => 90],
        ], $meta));
    }

    public function test_drafts_social_post_from_accepted_clip(): void
    {
        $clip = $this->acceptedClipCandidate();

        $draft = app(SocialDraftGenerator::class)->draftForClip($clip);

        $this->assertNotNull($draft);
        $this->assertSame(ContentCandidate::TYPE_SOCIAL_POST, $draft->type);
        $this->assertSame(ContentCandidate::STATUS_DRAFT, $draft->status);
        $this->assertSame($clip->id, $draft->parent_id);
        $this->assertSame($clip->lesson_id, $draft->lesson_id);
        $this->assertSame('vk_wall', $draft->channel_target);
        $this->assertStringContainsString('Фрагмент про алфавит', $draft->body);
    }

    public function test_quote_is_grounded_in_the_clip_span(): void
    {
        $clip = $this->acceptedClipCandidate();

        $draft = app(SocialDraftGenerator::class)->draftForClip($clip);

        $this->assertNotNull($draft->quote);
        $this->assertStringContainsString('Санскрит', $draft->quote);
        // The sentence outside [0, 90) must never leak into the quote.
        $this->assertStringNotContainsString('диапазона', $draft->quote);
    }

    public function test_returns_null_for_non_clip_candidate(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $candidate = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_SOCIAL_POST,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'lesson_id' => $lesson->id,
        ]);

        $this->assertNull(app(SocialDraftGenerator::class)->draftForClip($candidate));
    }

    public function test_returns_null_for_draft_clip_not_yet_accepted(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $clip = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_CLIP,
            'status' => ContentCandidate::STATUS_DRAFT,
            'lesson_id' => $lesson->id,
            'title' => 'Ещё не бесплатный',
        ]);

        $this->assertNull(app(SocialDraftGenerator::class)->draftForClip($clip));
    }

    public function test_idempotent_one_draft_per_clip(): void
    {
        $clip = $this->acceptedClipCandidate();

        app(SocialDraftGenerator::class)->draftForClip($clip);
        app(SocialDraftGenerator::class)->draftForClip($clip);

        $this->assertSame(
            1,
            ContentCandidate::where('parent_id', $clip->id)->where('type', ContentCandidate::TYPE_SOCIAL_POST)->count()
        );
    }
}
