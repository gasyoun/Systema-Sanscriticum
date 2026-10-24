<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5789 — контракт §798 на SuflerWeek0Autopilot::renderMd и двухфазность eval.
 *
 * (1) Append-semantics: исторически renderMd собирал строки через «+=» — на
 *     целочисленных списках это UNION, правая сторона молча выбрасывалась, и
 *     закоммиченный baseline-отчёт H5560 (PR #2938) обрывался после «По
 *     классам» (нет леджера, вердикта, подписи). Тест гоняет реальную команду
 *     и сверяет `^## ` заголовки отчёта с планом секций кода (5 секций в этом
 *     порядке) — возврат «+=» уронит счётчик до 2 и подпись исчезнет.
 * (2) Двухфазность на общем узле Ollama: все эмбеддинги (nomic-embed-text,
 *     /api/embed) обязаны завершиться ДО первого вызова генерации
 *     (qwen2.5:7b-instruct, /v1/chat/completions) — иначе узел гоняет модели
 *     попеременно, холодная перезагрузка эмбеддера дрожит в косинусе и
 *     top-3-ранги перевернуться между прогонами (H5648: 1.0 ↔ 0.85).
 *
 * Порядок вызовов ловится на Http::fake: fake-замыкание пишет тег каждого
 * исходящего запроса в один список — embed-вызовы не имеют права встретиться
 * после первого generation-вызова.
 */
class SuflerWeek0RenderMdContractTest extends TestCase
{
    use RefreshDatabase;

    private const OUT_JSON = 'tests/output/sufler-week0-rendermd-contract.json';

    private const OUT_MD = 'tests/output/sufler-week0-rendermd-contract.md';

    private const SECTION_PLAN = [
        'Числа week-0 (§20 п.2–3)',
        'Разбор вердиктов (часть A)',
        'Killgate-леджер (§13)',
        'Ограничения week-0 (честно)',
        'Вердикт',
    ];

    private const CORPUS = <<<'MD'
# FAQ test

## Политика и поддержка

### Оплата: блоки (поблочно vs целиком)
Оплата курса идёт поблочно или целиком за весь курс.

### Записи уроков и пропуски
Запись каждого занятия выкладывается в закрытый чат курса.

## Техподдержка

### Не работает вход в личный кабинет
Вход в кабинет: восстановление пароля по ссылке из письма.
MD;

    private string $tmpFaq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpFaq = storage_path('framework/testing/faq_h5789_rendermd.md');
        File::ensureDirectoryExists(dirname($this->tmpFaq));
        File::put($this->tmpFaq, self::CORPUS);

        config([
            'support.faq_rag.path' => $this->tmpFaq,
            'features.faq_rag_suggester' => true,
            'knowledge.driver' => 'ollama',
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.embedding_model' => 'test-model',
            'knowledge.dimensions' => 4,
            'knowledge.generation_base_url' => null,
            'knowledge.generation_model' => 'qwen2.5:7b-instruct',
            'knowledge.generation_timeout' => 5,
            'features.faq_hybrid_retrieval' => false,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(base_path(self::OUT_JSON));
        @unlink(base_path(self::OUT_MD));
        @unlink($this->tmpFaq);

        parent::tearDown();
    }

    public function test_render_md_appends_the_full_section_plan_and_does_not_truncate(): void
    {
        $exit = $this->artisan('sufler:week0-autopilot', [
            '--retriever' => 'bm25',
            '--rerank' => 'none',
            '--sample' => 5,
            '--out-json' => self::OUT_JSON,
            '--out-md' => self::OUT_MD,
        ])->run();
        $this->assertSame(0, $exit);
        Http::assertNothingSent();

        $md = (string) file_get_contents(base_path(self::OUT_MD));

        // §798 drill: считаем `^## ` заголовки против плана секций кода.
        preg_match_all('/^## (.+)$/mu', $md, $headers);
        $this->assertSame(
            self::SECTION_PLAN,
            $headers[1],
            'section plan violated: a += regression silently drops right-side sections',
        );

        // Хвост, который исторически выбрасывался, обязан существовать И идти
        // после «По классам», а отчёт обязан заканчиваться подписью.
        $perClassAt = mb_strpos($md, '### По классам');
        $this->assertNotFalse($perClassAt);
        $this->assertGreaterThan(
            $perClassAt,
            mb_strpos($md, '## Killgate-леджер (§13)'),
            'killgate ledger must render after the per-class table',
        );
        $this->assertStringContainsString('Связка метрик (риск конверсии)', $md);

        $nonEmpty = array_values(array_filter(explode("\n", $md), fn (string $l): bool => trim($l) !== ''));
        $this->assertSame('_Гасунс_', trim((string) end($nonEmpty)), 'report must end with the byline, not stop early');
    }

    public function test_two_phase_eval_embeds_everything_before_any_generation_call(): void
    {
        // Плотная нога живая: индекс (KnowledgeChunk) + fusion — двухфазность
        // проверяется на полном пути hybrid retrieval, а не на BM25-поле.
        config(['features.faq_hybrid_retrieval' => true]);
        KnowledgeChunk::create([
            'faq_chunk_id' => 'политика-и-поддержка/оплата-блоки-поблочно-vs-целиком',
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([0.7071, 0.7071, 0.0, 0.0]),
            'content_hash' => 'h5789-a',
        ]);
        KnowledgeChunk::create([
            'faq_chunk_id' => 'техподдержка/не-работает-вход-в-личный-кабинет',
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'h5789-b',
        ]);

        /** @var list<string> $order */
        $order = [];
        Http::fake(function ($request) use (&$order) {
            $url = (string) $request->url();
            if (str_contains($url, '/api/embed')) {
                $order[] = 'embed';

                return Http::response(['embeddings' => [[1.0, 0.0, 0.0, 0.0]]], 200);
            }

            $order[] = 'generation';

            return Http::response([
                'model' => 'qwen2.5:7b-instruct',
                'choices' => [['message' => ['content' => "A — прямо\nB — нет\nC — нет\n\nОтвет: A"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200);
        });

        $exit = $this->artisan('sufler:week0-autopilot', [
            '--retriever' => 'hybrid',
            '--rerank' => 'llm',
            '--sample' => 5,
            '--out-json' => self::OUT_JSON,
            '--out-md' => self::OUT_MD,
        ])->run();
        $this->assertSame(0, $exit);

        $firstGeneration = array_search('generation', $order, true);
        $this->assertNotFalse($firstGeneration, 'generation phase must run');
        $this->assertContains('embed', $order, 'dense leg (embedding phase) must run');
        $this->assertNotContains(
            'embed',
            array_slice($order, $firstGeneration + 1),
            'no embedding may follow the first generation call — interleave flips hybrid top-3 ranks',
        );

        $payload = json_decode((string) file_get_contents(base_path(self::OUT_JSON)), true);
        $this->assertSame('hybrid', $payload['retriever']);
        $this->assertSame('llm', $payload['rerank']);
        $this->assertSame(5, $payload['killgate_ledger']['rerank']['calls']);
        $this->assertSame(0, $payload['killgate_ledger']['rerank']['fallbacks']);
    }
}
