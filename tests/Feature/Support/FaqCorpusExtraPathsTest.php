<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqCorpusParser;
use Tests\TestCase;

/**
 * H3766 B3 (правка 4) — лекционный корпус faq_from_lectures.md участвует в BM25.
 *
 * В репозитории этот файл сейчас — пустой каркас (заголовок + комментарий), его
 * наполняет KnowledgeFaqPublisher при Accept faq_draft. Поэтому проводку нельзя
 * проверить на боевом корпусе: тест подставляет временные файлы.
 */
class FaqCorpusExtraPathsTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_extra_corpus_chunks_are_parsed_prefixed_and_retrievable(): void
    {
        $main = $this->writeCorpus('faq_main', <<<'MD'
        ## Политика и поддержка

        ### Записи уроков и пропуски

        Запись каждого занятия выкладывается в закрытый чат курса.
        MD);

        $lectures = $this->writeCorpus('faq_from_lectures', <<<'MD'
        ## FAQ из лекций

        ### Висарга перед глухими согласными

        Висарга в конце слова перед глухим согласным сохраняется как придыхание.
        MD);

        config()->set('support.faq_rag.path', $main);
        config()->set('support.faq_rag.extra_paths', [$lectures]);

        $ids = array_map(
            static fn ($c): string => $c->chunkId,
            app(FaqCorpusParser::class)->chunks(),
        );

        $this->assertContains('политика-и-поддержка/записи-уроков-и-пропуски', $ids);
        $this->assertContains(
            basename($lectures, '.md').'/faq-из-лекций/висарга-перед-глухими-согласными',
            $ids,
            'lecture chunk must be prefixed with the file stem so ids cannot collide with faq.md',
        );

        $hits = app(Bm25FaqRetriever::class)->retrieve('что происходит с висаргой перед глухим согласным', 3);
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('висарга', $hits[0]['chunk_id']);
    }

    public function test_explicit_path_argument_stays_single_file(): void
    {
        $main = $this->writeCorpus('faq_main_only', <<<'MD'
        ## Политика и поддержка

        ### Техподдержка

        Не проходит оплата — напишите куратору.
        MD);

        $lectures = $this->writeCorpus('faq_lectures_ignored', <<<'MD'
        ## FAQ из лекций

        ### Сандхи на стыке слов

        Правила сандхи разбираются на занятии.
        MD);

        config()->set('support.faq_rag.extra_paths', [$lectures]);

        $ids = array_map(
            static fn ($c): string => $c->chunkId,
            app(FaqCorpusParser::class)->chunks($main),
        );

        $this->assertSame(['политика-и-поддержка/техподдержка'], $ids);
    }

    private function writeCorpus(string $name, string $body): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$name.'_'.uniqid().'.md';
        file_put_contents($path, "# FAQ\n\n".$body."\n");
        $this->tmp[] = $path;

        return $path;
    }
}
