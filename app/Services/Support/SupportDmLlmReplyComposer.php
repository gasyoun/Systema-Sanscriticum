<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Services\Bot\CuratorAi;
use App\Services\Support\Faq\HybridRetriever;

/**
 * H4404 (рулинг MG 08-09-2026 «LLM-черновики»): формулировка ответа студенту
 * внешним LLM по живому FAQ-контексту — БЕЗ классификатора категорий.
 *
 * Почему без классификатора: в пробе H3380 категорийный конвейер не выстрелил
 * ни разу (category=null на 8 из 8 живых dm_hinted), значит любая ветка,
 * стоящая за категорией, мертва в реальном трафике. Здесь вопрос студента
 * идёт в retrieval напрямую; категория в решении «отвечать/молчать» не
 * участвует вовсе.
 *
 * Формулировка — единственная работа LLM. ФАКТЫ ответа берутся только из
 * FAQ-цитаты (как в {@see SupportDmAutoReply::faqDraft()}, рулинг R3):
 * промпт запрещает цифры, ссылки и сроки, которых нет в тексте. Модель не
 * получает ни суммы, ни доступа, ни каких-либо данных LMS — потому R3-запреты
 * денег/доступов здесь держит КОД, а не конфиг: вопрос про деньги гонится в
 * отказ до всякого вызова LLM.
 */
class SupportDmLlmReplyComposer
{
    /** Версия промпта. Меняется при правке формулировок — пишется в аудит. */
    public const PROMPT_VERSION = 'h4404-v1';

    public function __construct(
        private readonly CuratorAi $ai,
        private readonly HybridRetriever $faq,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_dm_llm_drafts', false);
    }

    /**
     * Сформулировать ответ студенту. null — LLM недоступен/отказался/не дал
     * текста; вызывающий обязан уйти в fallback (ack), ничего не отправляя.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array{draft: string, model: ?string, usage: ?array{prompt_tokens: int, completion_tokens: int}, chunk_ids: list<string>}|null
     */
    public function compose(string $questionText, array $hits): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $context = $this->faqContext($hits);
        if ($context === null) {
            return null;
        }

        $result = $this->ai->chatWithUsage([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($questionText, $context)],
        ]);

        $draft = $result['content'];
        if ($draft === null) {
            return null;
        }

        return [
            'draft' => $draft,
            'model' => $result['model'],
            'usage' => $result['usage'],
            'chunk_ids' => array_map(
                static fn (array $hit): string => (string) ($hit['chunk_id'] ?? ''),
                $hits,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return array{context: string, titles: list<string>}|null
     */
    private function faqContext(array $hits): ?array
    {
        $parts = [];
        $titles = [];

        foreach ($hits as $hit) {
            $snippet = trim((string) ($hit['snippet'] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $title = trim((string) ($hit['title'] ?? ''));
            $parts[] = ($title === '' ? '' : $title."\n").$snippet;
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        if ($parts === []) {
            return null;
        }

        return ['context' => implode("\n\n---\n\n", $parts), 'titles' => $titles];
    }

    private function systemPrompt(): string
    {
        return 'Ты — помощник куратора школы санскрита. Студент написал в личную поддержку. '
            .'Составь вежливый ОТВЕТ студенту на русском, опираясь СТРОГО на приведённые '
            .'фрагменты справки. НЕ выдумывай цифры, ссылки, сроки и правила, которых нет '
            .'во фрагментах. Если точного ответа во фрагментах нет — вежливо скажи, что '
            .'вопрос передан куратору, и он ответит в течение рабочего дня. '
            // Домашний регистр (H1876, revenue-copy voice) — тот же голос, что и
            // в шаблонных канреплая и SupportLlmDraftComposer.
            .'Регистр ответа: обращение на «вы» со строчной буквы; тон спокойный, взрослый, конкретный. '
            .'Без эмодзи, без восклицательных знаков, без нагнетания срочности, без уменьшительных '
            .'и без англицизмов там, где есть русское слово. '
            .'Букву «ё» не используй; исключение — «всё», когда без неё текст читался бы как «все». '
            .'Верни только текст ответа, без пояснений.';
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     */
    private function userPrompt(string $questionText, array $context): string
    {
        return "Фрагменты справки:\n".$context['context']
            ."\n\nВопрос студента:\n".$questionText
            ."\n\nСоставь ответ.";
    }
}
