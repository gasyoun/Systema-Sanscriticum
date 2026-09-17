<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeContext;
use App\Services\Support\SupportDmLlmReplyComposer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5065 — чья модель формулирует ответ студенту.
 *
 * При `support_dm_llm_drafts_local=true` формулировка уходит на локальный узел
 * (Ollama), и внешний провайдер не вызывается НИКОГДА — включая случай, когда
 * локальный узел молчит. Это не оптимизация стоимости, а свойство приватности:
 * «локальный режим с откатом на внешний» означал бы, что вопрос студента всё
 * равно уезжает наружу, просто вторым шагом (контракт H3234, этап 6).
 */
class SupportDmLlmLocalGenerationTest extends TestCase
{
    private const QUESTION = 'куда загружать домашнее задание и в каком формате';

    private const ANSWER = 'Домашнее задание загружается в личном кабинете на странице урока.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_llm_drafts' => true,
            'features.support_dm_llm_drafts_local' => false,
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.generation_model' => 'qwen3:14b',
            'knowledge.generation_timeout' => 5,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.example/api/v1',
            'services.openrouter.model' => 'deepseek/deepseek-v4-flash',
        ]);
    }

    public function test_local_flag_sends_the_question_to_the_local_node_and_records_its_model(): void
    {
        config(['features.support_dm_llm_drafts_local' => true]);

        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'model' => 'qwen3:14b',
                'choices' => [['message' => ['content' => self::ANSWER]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], 200),
            'openrouter.example/*' => Http::response(['error' => 'must not be called'], 500),
        ]);

        $result = app(SupportDmLlmReplyComposer::class)->compose(self::QUESTION, $this->context());

        $this->assertNotNull($result);
        $this->assertSame(self::ANSWER, $result['draft']);
        $this->assertSame('qwen3:14b', $result['model']);
        $this->assertSame([self::MATERIALS_CHUNK], $result['chunk_ids']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1:11434'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.example'));
    }

    public function test_default_still_uses_the_external_provider(): void
    {
        Http::fake([
            'openrouter.example/*' => Http::response([
                'model' => 'deepseek/deepseek-v4-flash',
                'choices' => [['message' => ['content' => self::ANSWER]]],
            ], 200),
            '127.0.0.1:11434/*' => Http::response(['error' => 'must not be called'], 500),
        ]);

        $result = app(SupportDmLlmReplyComposer::class)->compose(self::QUESTION, $this->context());

        $this->assertNotNull($result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.example'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1:11434'));
    }

    public function test_local_node_is_down_means_no_formulation_and_never_an_external_fallback(): void
    {
        config(['features.support_dm_llm_drafts_local' => true]);

        Http::fake([
            '127.0.0.1:11434/*' => Http::response('node down', 503),
            'openrouter.example/*' => Http::response([
                'choices' => [['message' => ['content' => 'внешний ответ, которого быть не должно']]],
            ], 200),
        ]);

        $result = app(SupportDmLlmReplyComposer::class)->compose(self::QUESTION, $this->context());

        $this->assertNull($result, 'локальный узел недоступен → формулировки нет (полоса уйдёт в шаблон/подсказку)');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.example'));
    }

    private const MATERIALS_CHUNK = 'политика-и-поддержка/домашние-задания-и-проверка';

    private function context(): KnowledgeContext
    {
        $parser = app(FaqCorpusParser::class);
        $chunk = null;

        foreach ($parser->parseFile(base_path('tests/fixtures/faq_live_f_corpus.md')) as $candidate) {
            if ($candidate->chunkId === self::MATERIALS_CHUNK) {
                $chunk = $candidate;
                break;
            }
        }

        $this->assertNotNull($chunk, 'фикстура корпуса обязана содержать раздел с домашними заданиями');

        return new KnowledgeContext([
            ['chunk' => $chunk, 'score' => 9.0, 'bm25_score' => 9.0],
        ], self::QUESTION);
    }
}
