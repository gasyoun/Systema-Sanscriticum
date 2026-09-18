<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H5065 — knowledge:coverage: «вся инфа из фака» доехала до базы знаний.
 *
 * Тест держит три вещи:
 *  1. на живом faq.md потерь нет (это и есть ответ на вопрос «вся ли»);
 *  2. дубль заголовочного пути ВАЛИТ команду — в knowledge_chunks ключ
 *     faq_chunk_id уникален, поэтому два тела с одним id означают, что одно
 *     из них молча исчезло из индекса;
 *  3. --require-embedded ловит «корпус распарсился, но не проэмбеден» —
 *     без флага это предупреждение, с флагом провал.
 */
class KnowledgeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->dir = storage_path('framework/testing/kcov_'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_real_corpus_has_no_silent_section_loss(): void
    {
        // Настоящий faq.md через настоящий путь конфига (extra_paths тоже
        // проверяются — именно там живёт faq_from_lectures.md).
        $this->artisan('knowledge:coverage')
            ->expectsOutputToContain('all present')
            ->assertExitCode(0);
    }

    public function test_duplicate_heading_path_fails_the_check(): void
    {
        // Два разных тела под ОДНИМ заголовочным путём: chunk_id совпадает,
        // и в knowledge_chunks останется только второе тело. Это потеря, а не
        // косметика, и команда обязана её назвать.
        $corpus = $this->write('duplicate.md', <<<'MD'
        # FAQ

        ## Политика и поддержка

        ### Оплата

        Оплата идёт поблочно.

        ### Оплата

        Второе тело того же заголовка — оно затрёт первое.
        MD);

        $this->artisan('knowledge:coverage', ['--path' => $corpus])
            ->expectsOutputToContain('дублирующихся chunk_id')
            ->assertExitCode(1);
    }

    public function test_require_embedded_fails_when_chunks_are_not_embedded(): void
    {
        $corpus = $this->write('plain.md', <<<'MD'
        # FAQ

        ## Раздел

        ### Сертификат

        Грамота выдаётся по окончании курса.
        MD);

        config(['support.faq_rag.extra_paths' => []]);

        $this->artisan('knowledge:coverage', ['--path' => $corpus])
            ->assertExitCode(0);

        $this->artisan('knowledge:coverage', ['--path' => $corpus, '--require-embedded' => true])
            ->expectsOutputToContain('без эмбеддинга')
            ->assertExitCode(1);
    }

    public function test_require_embedded_passes_when_every_hash_matches(): void
    {
        $corpus = $this->write('indexed.md', <<<'MD'
        # FAQ

        ## Раздел

        ### Сертификат

        Грамота выдаётся по окончании курса.
        MD);

        config([
            'support.faq_rag.extra_paths' => [],
            'knowledge.embedding_model' => 'test-model',
            'knowledge.dimensions' => 4,
        ]);

        $parser = app(FaqCorpusParser::class);
        $chunks = $parser->parseFile($corpus);
        $this->assertCount(1, $chunks);

        KnowledgeChunk::create([
            'faq_chunk_id' => $chunks[0]->chunkId,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => KnowledgeVectors::contentHash('test-model', 4, $chunks[0]->searchText()),
        ]);

        // extra_paths пуст, но coverage по --path смотрит только файл: хэши
        // обязаны сойтись, иначе «проэмбедено» ничего не значит.
        $this->artisan('knowledge:coverage', ['--path' => $corpus, '--require-embedded' => true])
            ->assertExitCode(0);
    }

    public function test_stale_hash_fails_require_embedded(): void
    {
        $corpus = $this->write('stale.md', <<<'MD'
        # FAQ

        ## Раздел

        ### Сертификат

        Грамота выдаётся по окончании курса.
        MD);

        config([
            'support.faq_rag.extra_paths' => [],
            'knowledge.embedding_model' => 'test-model',
            'knowledge.dimensions' => 4,
        ]);

        $chunks = app(FaqCorpusParser::class)->parseFile($corpus);

        KnowledgeChunk::create([
            'faq_chunk_id' => $chunks[0]->chunkId,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'hash-of-an-older-body',
        ]);

        $this->artisan('knowledge:coverage', ['--path' => $corpus, '--require-embedded' => true])
            ->assertExitCode(1);
    }

    private function write(string $name, string $body): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        // Отступ heredoc'а — формат теста, а не корпуса: заголовки обязаны
        // начинаться с первого символа строки, иначе парсер их не увидит.
        $normalized = implode("\n", array_map(
            static fn (string $line): string => preg_replace('/^ {8}/', '', $line) ?? $line,
            explode("\n", $body),
        ));
        File::put($path, $normalized);

        return $path;
    }
}
