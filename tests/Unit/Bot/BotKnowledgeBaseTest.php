<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\BotKnowledgeBase;
use App\Services\Bot\CourseCatalogProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H3766 B4 (стадия 1 issue #1633, рулинг R5) — ретривал в базе знаний бота.
 *
 * Три вещи, которые обязаны остаться верными при включённом флаге:
 * 1. в промпт попадает нужный раздел, а не весь корпус;
 * 2. каталог курсов не режется никогда — цены только оттуда;
 * 3. при выключенном флаге промпт байт-в-байт прежний.
 */
class BotKnowledgeBaseTest extends TestCase
{
    private string $corpus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corpus = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bot_kb_'.uniqid().'.md';
        file_put_contents($this->corpus, <<<'MD'
        # FAQ

        ## Политика и поддержка

        ### Личный кабинет на сайте

        Личный кабинет на сайте — личное хранилище доступов: оплаченные курсы, записи занятий и квитанции.

        ### Записи уроков и пропуски

        Запись каждого занятия выкладывается в закрытый чат курса в Telegram.

        ### Сертификат

        Грамота выдается по окончании курса, часто после небольшого аттестационного задания.

        ### Материалы и учебники

        Базовый комплект для санскрита с нуля — учебник Кочергиной и санскритско-русский словарь.

        ### Возврат, пауза, перенос

        Возврат за непройденные занятия оформляется заявлением.

        ### Размер группы

        В активных языковых группах обычно 15–20 человек.

        ### Часовой пояс, город, формат

        Всё указанное время — московское. Формат — онлайн, Zoom.

        MD);

        config([
            'support.faq_rag.path' => $this->corpus,
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.bot_top_k' => 2,
        ]);

        Cache::flush();

        $this->mock(CourseCatalogProvider::class, function ($mock): void {
            $mock->shouldReceive('markdown')->andReturn("# Каталог курсов\n\nГрамматика санскрита — 6000 ₽.");
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->corpus);
        parent::tearDown();
    }

    public function test_flag_off_keeps_the_whole_corpus_in_the_prompt(): void
    {
        config(['features.bot_faq_retrieval' => false]);

        $faq = app(BotKnowledgeBase::class)->faqFor('где мой личный кабинет');

        $this->assertStringContainsString('Личный кабинет на сайте', $faq);
        $this->assertStringContainsString('Размер группы', $faq, 'при выключенном флаге корпус не режется');
    }

    public function test_flag_on_narrows_the_prompt_to_the_relevant_sections(): void
    {
        config(['features.bot_faq_retrieval' => true]);

        $faq = app(BotKnowledgeBase::class)->faqFor('где мой личный кабинет и как туда войти');

        $this->assertStringContainsString('личное хранилище доступов', $faq);
        $this->assertStringNotContainsString('15–20 человек', $faq, 'нерелевантные разделы в промпт не идут');
        $this->assertLessThan(
            mb_strlen((string) file_get_contents($this->corpus)),
            mb_strlen($faq),
            'смысл стадии 1 — промпт короче корпуса',
        );
    }

    public function test_flag_on_without_a_question_falls_back_to_the_whole_corpus(): void
    {
        config(['features.bot_faq_retrieval' => true]);

        $faq = app(BotKnowledgeBase::class)->faqFor(null);

        $this->assertStringContainsString('Размер группы', $faq);
    }

    /**
     * Пустой ретривал — не повод остаться без базы знаний: без неё персона
     * начинает выдумывать, а это худший исход из возможных.
     */
    public function test_unmatchable_question_falls_back_to_the_whole_corpus(): void
    {
        config(['features.bot_faq_retrieval' => true]);

        $faq = app(BotKnowledgeBase::class)->faqFor('!!! ???');

        $this->assertStringContainsString('Размер группы', $faq);
    }

    public function test_course_catalog_is_never_narrowed(): void
    {
        config(['features.bot_faq_retrieval' => true]);

        $prompt = app(BotKnowledgeBase::class)->systemPrompt('где мой личный кабинет');

        $this->assertStringContainsString('Каталог курсов', $prompt);
        $this->assertStringContainsString('6000 ₽', $prompt, 'цены идут только из каталога и режутся никогда');
    }
}
