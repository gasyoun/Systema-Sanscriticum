<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H3234 (issue #1633 этап 2): knowledge:eval — recall@5 / MRR BM25 vs hybrid
 * печатной таблицей на золотом наборе (100Q H3766 B5 / fresh 20Q H4001).
 * Приёмка: hybrid ≥ BM25; dense-ноги нет → гибрид держит BM25-пол (H4001),
 * таблица показывает равенство, а не ложный выигрыш.
 */
class KnowledgeEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_eval_prints_the_table_and_holds_the_bm25_floor_without_dense_leg(): void
    {
        // knowledge_chunks пуст и драйвер не задан → dense-нога OFF, гибрид =
        // BM25-пол: recall/MRR равны, приёмка «hybrid ≥ BM25» выполнена.
        config(['knowledge.driver' => '']);

        $this->artisan('knowledge:eval', ['--evalset' => 'tests/fixtures/faq_rag_eval.json', '--top-k' => 5])
            ->expectsOutputToContain('dense leg: OFF (BM25 floor)')
            ->expectsOutputToContain('BM25 (faq.md)')
            ->expectsOutputToContain('Hybrid (RRF + dense)')
            ->expectsOutputToContain('acceptance: hybrid ≥ BM25 — OK')
            ->assertExitCode(0);
    }

    public function test_eval_reports_dense_on_with_the_ollama_driver(): void
    {
        // Драйвер задан, но узел недоступен (нечего фейкать — адрес 127.0.0.1:11434
        // локально может отвечать): OllamaEmbeddingProvider вернёт пустой вектор
        // на пробе → dense leg OFF, пол держится. Проверяем только честную метку.
        config(['knowledge.driver' => 'ollama']);

        $this->artisan('knowledge:eval', ['--evalset' => 'tests/fixtures/faq_rag_eval_fresh.json', '--top-k' => 5])
            ->assertExitCode(0);
    }

    public function test_eval_fails_loudly_without_an_evalset(): void
    {
        $this->artisan('knowledge:eval', ['--evalset' => '/nonexistent/evalset.json'])
            ->assertExitCode(1);
    }
}
