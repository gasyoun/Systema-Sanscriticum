<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeContext;
use App\Services\Support\Faq\SharedKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H5065 — общая база знаний: один вид контекста на все отвечающие поверхности.
 *
 * Главное утверждение набора: в промпт уходит РАЗДЕЛ, а не 280-символьный
 * сниппет. До H5065 кабинетный бот получал разделы целиком, а отвечающая полоса
 * — сниппеты из того же ретривала; «общая база знаний» была общей только по
 * файлу на диске, и модель на длинном разделе договаривала пропущенное.
 */
class SharedKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private string $corpus;

    /** Длинный раздел: заведомо длиннее сниппетных 280 символов. */
    private const LONG_TAIL = 'ХВОСТ-КОТОРЫЙ-СРЕЗАЕТ-СНИППЕТ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->corpus = storage_path('framework/testing/skb_'.uniqid().'.md');
        File::ensureDirectoryExists(dirname($this->corpus));

        $long = str_repeat('Домашнее задание загружается в кабинете. ', 12).self::LONG_TAIL;

        File::put($this->corpus, implode("\n", [
            '# FAQ',
            '',
            '## Политика и поддержка',
            '',
            '### Домашние задания',
            $long,
            '',
            '### Сертификат',
            'Грамота выдаётся по окончании курса.',
            '',
        ]));

        config([
            'support.faq_rag.path' => $this->corpus,
            'support.faq_rag.extra_paths' => [],
            'features.faq_rag_suggester' => true,
            'support.faq_rag.answer_top_k' => 2,
            'support.faq_rag.answer_max_chars' => 0,
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->corpus);
        parent::tearDown();
    }

    public function test_context_carries_the_whole_section_not_a_snippet(): void
    {
        $context = app(SharedKnowledgeBase::class)->context('куда загружать домашнее задание');

        $this->assertFalse($context->isEmpty());
        $block = $context->promptBlock();

        $this->assertStringContainsString(
            self::LONG_TAIL,
            $block,
            'полный раздел обязан доехать до промпта: сниппет срезал бы хвост',
        );
        $this->assertStringContainsString('## Политика и поддержка → Домашние задания', $block);
        $this->assertGreaterThan(280, mb_strlen($block), 'контекст длиннее сниппета');
    }

    public function test_prompt_block_capped_cuts_on_a_section_boundary(): void
    {
        // Порядок задаётся вручную: проверяем обрезку, а не ранжирование —
        // иначе тест зеленел бы или падал от того, какой раздел ретривал поднял
        // первым.
        $parser = app(FaqCorpusParser::class);
        $chunks = $parser->parseFile($this->corpus);
        $long = collect($chunks)->first(fn ($c): bool => str_contains($c->body, self::LONG_TAIL));
        $short = collect($chunks)->first(fn ($c): bool => str_contains($c->body, 'Грамота выдаётся'));

        $this->assertNotNull($long);
        $this->assertNotNull($short);

        $context = new KnowledgeContext([
            ['chunk' => $long, 'score' => 9.0, 'bm25_score' => 9.0],
            ['chunk' => $short, 'score' => 8.0, 'bm25_score' => 8.0],
        ], 'задание и сертификат');

        $capped = $context->promptBlockCapped(120);

        $this->assertStringContainsString(self::LONG_TAIL, $capped, 'первый раздел отдаётся целиком');
        $this->assertStringNotContainsString(
            'Грамота выдаётся',
            $capped,
            'обрезанный по границе раздел не оставляет половину своего тела',
        );
    }

    public function test_blank_question_yields_an_empty_context(): void
    {
        $context = app(SharedKnowledgeBase::class)->context('   ');

        $this->assertTrue($context->isEmpty());
        $this->assertSame('', $context->promptBlock());
        $this->assertSame(0.0, $context->bestBm25Score());
    }

    public function test_top_k_is_respected_and_citations_keep_the_heading_path(): void
    {
        $context = app(SharedKnowledgeBase::class)->context('домашнее задание сертификат', 1);

        $this->assertSame(1, $context->count());

        $citation = $context->citations()[0];
        $this->assertSame('Политика и поддержка', $citation['heading_path'][0], 'заголовочный путь остаётся в цитате');
        $this->assertArrayHasKey('bm25_score', $citation, 'цитата несёт домен порогов отдельным полем');
        // Цитата округляет скор для аудита, контекст отдаёт точный — сравниваем
        // с допуском, а не побайтово.
        $this->assertEqualsWithDelta($citation['bm25_score'], $context->bestBm25Score(), 0.0001);
    }

    public function test_bm25_score_falls_back_to_the_plain_score_on_the_bm25_floor(): void
    {
        // BM25-пол: гибрид не зовётся, bm25_score отсутствует — домен порога
        // обязан читаться из score, иначе выключенная плотная нога обнулила бы
        // все пороги.
        $floor = KnowledgeContext::empty('q');
        $this->assertSame(0.0, $floor->bestBm25Score());

        $context = new KnowledgeContext([
            ['chunk' => app(SharedKnowledgeBase::class)->context('сертификат', 1)->hits[0]['chunk'], 'score' => 7.5, 'bm25_score' => 7.5],
        ], 'сертификат');

        $this->assertSame(7.5, $context->bestBm25Score());
    }
}
