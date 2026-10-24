<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Bot\CuratorAi;
use App\Services\Support\Faq\FaqLlmReranker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5648 — трек --rerank=llm автопилота week-0: LLM выбирает чанк из top-K
 * кандидатов retrieval (FaqLlmReranker на CuratorAi-стеке), токены каждого
 * вызова идут в killgate-леджер, отказ узла = фолбэк на порядок retrieval.
 *
 * Локальный узел — Http::fake (тот же паттерн, что SupportDmLlmLocalGeneration
 * / KnowledgeLocalGenerationTest): реранк обязан ходить через существующий
 * CuratorAi-клиент, а не собственный HTTP.
 *
 * H5771 — срыв транспорта (raw=null/пустой ответ) ретраится ровно один раз;
 * повторный срыв = фолбэк; разобранный ответ ретраю не подлежит.
 */
class SuflerWeek0AutopilotRerankTest extends TestCase
{
    private const OUT_JSON = 'tests/output/sufler-week0-rerank-test.json';

    private const OUT_MD = 'tests/output/sufler-week0-rerank-test.md';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.faq_rag_suggester' => true,
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            // Чтобы dev-.env с раздельным generation_base_url не протекал в тесты:
            // по умолчанию генерация ходит туда же, куда эмбеддинги.
            'knowledge.generation_base_url' => null,
            'knowledge.generation_model' => 'qwen2.5:7b-instruct',
            'knowledge.generation_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(base_path(self::OUT_JSON));
        @unlink(base_path(self::OUT_MD));

        parent::tearDown();
    }

    public function test_rerank_llm_promotes_the_picked_chunk_and_ledgers_tokens(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'model' => 'qwen2.5:7b-instruct',
                'choices' => [['message' => ['content' => "A — упоминает попутно\nB — прямо\nC — нет\n\nОтвет: B"]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], 200),
        ]);

        $exit = $this->runAutopilot(['--rerank' => 'llm', '--sample' => 5]);
        $this->assertSame(0, $exit);

        $payload = json_decode((string) file_get_contents(base_path(self::OUT_JSON)), true);

        $this->assertSame('llm', $payload['rerank']);
        $ledger = $payload['killgate_ledger'];
        $this->assertSame('llm_rerank_local (CuratorAi localChatWithUsage — стек суфлёра, без нового HTTP)', $ledger['track']);
        $this->assertSame(5, $ledger['rerank']['calls']);
        $this->assertSame(0, $ledger['rerank']['fallbacks']);
        $this->assertSame(5 * 150, $ledger['tokens_used_total']);
        $this->assertSame(['qwen2.5:7b-instruct'], $ledger['rerank']['models']);

        // «Ответ: B» → всюду второй чанк пула становится top-1.
        $rows = $payload['citation_precision_n']['rows'];
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(1, $row['rerank_rank']);
            $this->assertFalse($row['rerank_fallback']);
            $this->assertSame($row['rerank_pick'], $row['top1']);
            $this->assertNotSame($row['top1_retrieval'], $row['top1']);
        }
    }

    public function test_rerank_llm_falls_back_to_retrieval_order_when_node_is_silent(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response(['error' => 'node down'], 500),
        ]);

        $exit = $this->runAutopilot(['--rerank' => 'llm', '--sample' => 5]);
        $this->assertSame(0, $exit);

        $payload = json_decode((string) file_get_contents(base_path(self::OUT_JSON)), true);

        // Фолбэк = порядок retrieval: реранк не может опуститься ниже пола.
        $this->assertSame(5, $payload['killgate_ledger']['rerank']['fallbacks']);
        $this->assertSame(0, $payload['killgate_ledger']['tokens_used_total']);
        foreach ($payload['citation_precision_n']['rows'] as $row) {
            $this->assertTrue($row['rerank_fallback']);
            $this->assertSame($row['top1_retrieval'], $row['top1']);
        }
    }

    public function test_rerank_none_keeps_the_deterministic_track(): void
    {
        $exit = $this->runAutopilot(['--rerank' => 'none', '--sample' => 5]);
        $this->assertSame(0, $exit);

        $payload = json_decode((string) file_get_contents(base_path(self::OUT_JSON)), true);

        $this->assertSame('none', $payload['rerank']);
        $this->assertSame('deterministic_offline (LLM не вызывался)', $payload['killgate_ledger']['track']);
        $this->assertSame(0, $payload['killgate_ledger']['tokens_used_total']);
        $this->assertArrayNotHasKey('rerank', $payload['killgate_ledger']);
        Http::assertNothingSent();
    }

    public function test_invalid_rerank_flag_fails(): void
    {
        $this->artisan('sufler:week0-autopilot', ['--rerank' => 'bogus'])
            ->expectsOutputToContain('--rerank must be none|llm')
            ->assertFailed();
    }

    public function test_generation_base_url_splits_generation_from_embeddings(): void
    {
        // H5703: генерация (реранк) может жить на другом узле, чем эмбеддинги;
        // не задан — общий base_url, прод байт-в-байт не меняется.
        config(['knowledge.generation_base_url' => 'http://127.0.0.1:11435']);
        Http::fake([
            '127.0.0.1:11435/*' => Http::response([
                'model' => 'qwen3:14b',
                'choices' => [['message' => ['content' => "A — прямо\nB — нет\n\nОтвет: A"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
            '127.0.0.1:11434/*' => Http::response(['error' => 'must not be called'], 500),
        ]);

        $result = app(CuratorAi::class)->localChatWithUsage([['role' => 'user', 'content' => 'q']]);

        $this->assertSame("A — прямо\nB — нет\n\nОтвет: A", $result['content']);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '127.0.0.1:11435/v1/chat/completions'));
    }

    public function test_rerank_retries_once_on_transport_failure_and_sums_usage(): void
    {
        // H5771: первая попытка — пустой контент при живом usage (срыв узла),
        // ретрай отвечает «Ответ: B»; токены обеих попыток суммируются в леджер.
        Http::fake([
            '127.0.0.1:11434/*' => Http::sequence()
                ->push([
                    'model' => 'qwen2.5:7b-instruct',
                    'choices' => [['message' => ['content' => '   ']]],
                    'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
                ], 200)
                ->push([
                    'model' => 'qwen2.5:7b-instruct',
                    'choices' => [['message' => ['content' => "A — нет\nB — прямо\n\nОтвет: B"]]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ], 200),
        ]);

        $verdict = app(FaqLlmReranker::class)->rerank('Сколько длится блок занятий?', $this->hits());

        $this->assertFalse($verdict['fallback']);
        $this->assertSame('c-b', $verdict['pick']);
        $this->assertSame(1, $verdict['rank']);
        $this->assertSame(17, $verdict['usage']['prompt_tokens']);
        $this->assertSame(8, $verdict['usage']['completion_tokens']);
        $this->assertSame("A — нет\nB — прямо\n\nОтвет: B", $verdict['raw']);
        Http::assertSentCount(2);
    }

    public function test_rerank_falls_back_after_second_transport_failure(): void
    {
        // Обе попытки сорвались → обычный фолбэк к порядку retrieval, usage пуст.
        Http::fake([
            '127.0.0.1:11434/*' => Http::response(['error' => 'node down'], 500),
        ]);

        $verdict = app(FaqLlmReranker::class)->rerank('Сколько длится блок занятий?', $this->hits());

        $this->assertTrue($verdict['fallback']);
        $this->assertSame('c-a', $verdict['pick']);
        $this->assertSame(0, $verdict['rank']);
        $this->assertNull($verdict['usage']);
        $this->assertNull($verdict['raw']);
        Http::assertSentCount(2);
    }

    public function test_rerank_does_not_retry_a_parsed_answer(): void
    {
        // Разобранный ответ — не транспортный срыв: ретрая нет, ровно один вызов.
        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'model' => 'qwen2.5:7b-instruct',
                'choices' => [['message' => ['content' => "A — прямо\nB — нет\n\nОтвет: A"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $verdict = app(FaqLlmReranker::class)->rerank('Сколько длится блок занятий?', $this->hits());

        $this->assertFalse($verdict['fallback']);
        $this->assertSame('c-a', $verdict['pick']);
        Http::assertSentCount(1);
    }

    /**
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string}>
     */
    private function hits(): array
    {
        return [
            ['chunk_id' => 'c-a', 'title' => 'Блок занятий', 'heading_path' => ['Оплата'], 'snippet' => 'Блок = 4 занятия, оплата поблочно.', 'source' => 'faq'],
            ['chunk_id' => 'c-b', 'title' => 'Расписание', 'heading_path' => ['Занятия'], 'snippet' => 'Занятия идут по вечерам.', 'source' => 'faq'],
            ['chunk_id' => 'c-c', 'title' => 'Тетради', 'heading_path' => ['Материалы'], 'snippet' => 'Тетради студент приносит сам.', 'source' => 'faq'],
        ];
    }

    /**
     * @param  array<string, string|int>  $options
     */
    private function runAutopilot(array $options): int
    {
        $exit = $this->artisan('sufler:week0-autopilot', $options + [
            '--retriever' => 'bm25',
            '--out-json' => self::OUT_JSON,
            '--out-md' => self::OUT_MD,
        ])->run();

        return $exit;
    }
}
