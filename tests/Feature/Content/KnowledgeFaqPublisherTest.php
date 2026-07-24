<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\KnowledgeFaqPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * KnowledgeFaqPublisher + Accept chain (H1549 Wave 3, VERIFICATION C2).
 * Writes a temp knowledge file — never the committed ORS-export faq.md.
 */
class KnowledgeFaqPublisherTest extends TestCase
{
    use RefreshDatabase;

    private string $tempFaqPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempFaqPath = storage_path('framework/testing/faq_from_lectures_'.uniqid('', true).'.md');
        config(['content.lecture_faq_path' => $this->tempFaqPath]);
        Cache::forget(KnowledgeFaqPublisher::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFaqPath)) {
            @unlink($this->tempFaqPath);
        }
        parent::tearDown();
    }

    public function test_accept_writes_knowledge_target_and_marks_published(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $draft = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_FAQ_DRAFT,
            'status' => ContentCandidate::STATUS_DRAFT,
            'lesson_id' => $lesson->id,
            'title' => 'Что такое санскрит?',
            'body' => 'Древний язык Индии, основа многих традиций.',
            'channel_target' => 'cabinet_faq',
        ]);

        $draft->update(['status' => ContentCandidate::STATUS_ACCEPTED]);

        $draft->refresh();
        $this->assertSame(ContentCandidate::STATUS_PUBLISHED, $draft->status);
        $this->assertNotNull($draft->published_at);
        $this->assertFileExists($this->tempFaqPath);

        $content = (string) file_get_contents($this->tempFaqPath);
        $this->assertStringContainsString("<!-- content_candidate:{$draft->id} -->", $content);
        $this->assertStringContainsString('### Что такое санскрит?', $content);
        $this->assertStringContainsString('Древний язык Индии', $content);
    }

    public function test_publish_is_idempotent_on_second_accept(): void
    {
        $draft = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_FAQ_DRAFT,
            'status' => ContentCandidate::STATUS_DRAFT,
            'title' => 'Как учить деванагари?',
            'body' => 'Постепенно, по группам знаков.',
        ]);

        $draft->update(['status' => ContentCandidate::STATUS_ACCEPTED]);
        $first = (string) file_get_contents($this->tempFaqPath);

        app(KnowledgeFaqPublisher::class)->publish($draft->fresh());
        $second = (string) file_get_contents($this->tempFaqPath);

        $this->assertSame(1, substr_count($first, "content_candidate:{$draft->id}"));
        $this->assertSame(1, substr_count($second, "content_candidate:{$draft->id}"));
    }

    public function test_non_faq_types_are_ignored(): void
    {
        $social = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_SOCIAL_POST,
            'status' => ContentCandidate::STATUS_DRAFT,
            'title' => 'Пост',
            'body' => 'Текст',
        ]);

        $this->assertFalse(app(KnowledgeFaqPublisher::class)->publish(
            tap($social, fn ($c) => $c->status = ContentCandidate::STATUS_ACCEPTED)
        ));
        $this->assertFileDoesNotExist($this->tempFaqPath);
    }
}
