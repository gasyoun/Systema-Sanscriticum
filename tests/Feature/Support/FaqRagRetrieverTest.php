<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H2448 — FAQ BM25 retrieval for Helpdesk suggester (flag default OFF).
 */
class FaqRagRetrieverTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFaq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpFaq = storage_path('framework/testing/faq_rag_h2448.md');
        File::ensureDirectoryExists(dirname($this->tmpFaq));
        File::put($this->tmpFaq, <<<'MD'
# FAQ test

## Политика и поддержка

### Оплата: блоки (поблочно vs целиком)
Структура.
- 1 блок = 4 занятия. Оплата — поблочно или целиком за курс.

### Записи уроков и пропуски
- Запись каждого занятия выкладывается в закрытый чат курса в Telegram.

### Домашние задания и проверка
- Домашние задания проверяет преподаватель. Сроки согласовываются в группе.

### Возврат, пауза, перенос
- Возврат средств — через куратора по оплатам. Перенос оплаты по договорённости.
MD);

        config([
            'support.faq_rag.path' => $this->tmpFaq,
            'support.faq_rag.min_score' => 0.5,
            'support.faq_rag.top_k' => 3,
            'features.faq_rag_suggester' => false,
            'features.support_answer_suggester' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFaq)) {
            @unlink($this->tmpFaq);
        }
        parent::tearDown();
    }

    public function test_parser_builds_stable_chunk_ids_from_heading_path(): void
    {
        $chunks = app(FaqCorpusParser::class)->parseFile($this->tmpFaq);

        $this->assertNotEmpty($chunks);
        $ids = array_map(static fn ($c) => $c->chunkId, $chunks);
        $this->assertContains('политика-и-поддержка/оплата-блоки-поблочно-vs-целиком', $ids);
        $this->assertContains('политика-и-поддержка/записи-уроков-и-пропуски', $ids);
    }

    public function test_bm25_retrieve_returns_top_hits_with_chunk_id_and_snippet(): void
    {
        $hits = app(Bm25FaqRetriever::class)->retrieve('как оплатить блок поблочно', 3, $this->tmpFaq);

        $this->assertNotEmpty($hits);
        $this->assertArrayHasKey('chunk_id', $hits[0]);
        $this->assertArrayHasKey('snippet', $hits[0]);
        $this->assertArrayHasKey('score', $hits[0]);
        $this->assertStringContainsString('оплата', $hits[0]['chunk_id']);
    }

    public function test_flag_off_suggester_does_not_attach_faq_citations(): void
    {
        config([
            'features.support_answer_suggester' => true,
            'features.faq_rag_suggester' => false,
        ]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $user = User::factory()->create();
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Где посмотреть запись урока?',
            'is_read' => false,
        ]);

        // Recording category needs LMS schedule facts — without group, may create 0.
        // Flag-off path: even if a suggestion is created elsewhere, RAG must be inert.
        $this->assertFalse(app(Bm25FaqRetriever::class)->isEnabled());

        $result = app(SupportAnswerSuggester::class)->run();
        $this->assertIsArray($result);

        $withCite = SupportAnswerSuggestion::query()
            ->get()
            ->filter(fn (SupportAnswerSuggestion $s) => ! empty($s->facts['faq_citations'] ?? null));
        $this->assertCount(0, $withCite);
    }

    public function test_flag_on_payment_creates_suggestion_with_citations(): void
    {
        config([
            'features.support_answer_suggester' => true,
            'features.faq_rag_suggester' => true,
            'features.support_template_drafts' => false,
            'features.support_ai_assist' => false,
            'support.faq_rag.min_score' => 0.1,
        ]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $user = User::factory()->create();
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Как оплатить курс поблочно? Сколько стоит блок?',
            'is_read' => false,
        ]);

        $result = app(SupportAnswerSuggester::class)->run();
        $this->assertGreaterThanOrEqual(1, $result['created']);

        $suggestion = SupportAnswerSuggestion::query()->first();
        $this->assertNotNull($suggestion);
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_PAYMENT, $suggestion->category);
        $this->assertNotEmpty($suggestion->facts['faq_citations'] ?? null);
        $this->assertSame('faq_rag', $suggestion->facts['source'] ?? null);
        $this->assertStringContainsString('FAQ', $suggestion->draft_text);
    }

    public function test_money_category_refuses_when_score_below_threshold(): void
    {
        config([
            'features.support_answer_suggester' => true,
            'features.faq_rag_suggester' => true,
            'features.support_template_drafts' => false,
            'features.support_ai_assist' => false,
            // Impossible floor → refuse money drafts
            'support.faq_rag.min_score' => 9999.0,
        ]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $user = User::factory()->create();
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Сколько стоит курс? Можно ли оплатить позже?',
            'is_read' => false,
        ]);

        $result = app(SupportAnswerSuggester::class)->run();
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, SupportAnswerSuggestion::query()->count());
    }
}
