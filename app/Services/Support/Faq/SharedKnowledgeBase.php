<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use App\Services\Bot\BotKnowledgeBase;
use App\Services\Support\SupportDmLlmReplyComposer;

/**
 * H5065 — ОДНА общая база знаний на все отвечающие поверхности.
 *
 * Проблема, которую этот класс закрывает. Корпус (`resources/knowledge/faq.md`,
 * экспорт ORS-FAQ) один, а потребителей было три, и каждый собирал себе базу
 * знаний сам:
 *
 *  - кабинетный бот ({@see BotKnowledgeBase}) — полные
 *    разделы, но ТОЛЬКО лексическая нога (`Bm25FaqRetriever`), то есть
 *    проэмбедированный корпус для него не существовал;
 *  - личка саппорта ({@see SupportDmLlmReplyComposer}) —
 *    гибридный ретривал, но 280-символьные СНИППЕТЫ вместо разделов;
 *  - подсказка куратору — три сниппета, и это правильно (человеку нужна
 *    выжимка, а не простыня).
 *
 * Отсюда «общая база знаний» была общей только по файлу на диске. Здесь она
 * становится общей по ПОВЕДЕНИЮ: один ретривал, один вид контекста — полный
 * раздел плюс заголовочный путь плюс цитаты, — и один потолок размера.
 *
 * Контракт деградации наследуется от {@see HybridRetriever} и не
 * переизобретается: выключенный флаг полосы, пустой embedding, мёртвый
 * туннель, пустая `knowledge_chunks` — всё это даёт BM25-пол, байт-в-байт
 * равный прежнему поведению. Общая база знаний не может быть точкой отказа:
 * она — надстройка над уже работающим ретривалом.
 */
final class SharedKnowledgeBase
{
    public function __construct(
        private readonly HybridRetriever $retriever,
    ) {}

    /**
     * Гейт полосы отдаётся вызывающему — здесь его НЕТ намеренно.
     *
     * Так было и до общей базы знаний: {@see Bm25FaqRetriever::retrieve()}
     * флаг не читает, его читают потребители (SupportAnswerSuggester,
     * CabinetFaqTool), у каждого свой. Проверка флага ЗДЕСЬ сломала бы
     * кабинетного бота: он гейтится собственным `features.bot_faq_retrieval`,
     * а не полосой саппорта `features.faq_rag_suggester`, и на общей проверке
     * молча уехал бы в фолбэк «весь корпус».
     */
    public function isEnabled(): bool
    {
        return $this->retriever->isEnabled();
    }

    /**
     * Контекст вопроса. $topK = null → support.faq_rag.answer_top_k.
     *
     * Пустой вопрос не ищется вовсе: ретривал по пробелам вернул бы случайные
     * разделы — честнее пустой контекст.
     */
    public function context(string $question, ?int $topK = null): KnowledgeContext
    {
        $question = trim($question);
        if ($question === '') {
            return KnowledgeContext::empty($question);
        }

        $topK = max(1, $topK ?? $this->answerTopK());

        return new KnowledgeContext($this->retriever->retrieveChunks($question, $topK), $question);
    }

    /**
     * Сколько разделов уходит отвечающей стороне. Больше, чем top_k подсказки
     * куратору (3): человеку хватает одной цитаты, а отвечающей стороне
     * выпавший раздел стоит ответа «данных нет».
     */
    public function answerTopK(): int
    {
        return max(1, (int) config('support.faq_rag.answer_top_k', 6));
    }

    /**
     * Потолок символов контекста в промпте. 0 = без потолка.
     */
    public function answerMaxChars(): int
    {
        return max(0, (int) config('support.faq_rag.answer_max_chars', 12000));
    }

    /**
     * Блок промпта с уже применённым потолком — то, что вызывающие кладут в
     * сообщение модели, не думая о размере.
     */
    public function promptBlock(string $question, ?int $topK = null): string
    {
        return $this->context($question, $topK)->promptBlockCapped($this->answerMaxChars());
    }
}
