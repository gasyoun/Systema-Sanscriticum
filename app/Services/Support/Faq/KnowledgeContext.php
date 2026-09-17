<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * H5065 — контекст общей базы знаний: то, что реально уходит в промпт
 * отвечающей стороне (кабинетный бот, ИИ-куратор, полоса Telegram Business).
 *
 * Зачем отдельный объект, а не массив попаданий. До него каждая полоса
 * собирала промпт сама: кабинетный бот — из ПОЛНЫХ чанков, а личка саппорта —
 * из 280-символьных сниппетов (FaqChunk::snippet). Один и тот же ретривал
 * кормил две разные базы знаний, и «общая база знаний» существовала только на
 * словах: у бота был раздел целиком, у отвечающей полосы — его огрызок.
 *
 * Теперь текст раздела собирается ЗДЕСЬ и в одном виде: заголовочный путь
 * (`## Раздел → Подраздел`), затем тело целиком. Сниппет остаётся только для
 * цитаты-выжимки в подсказке куратору и в ответе студенту — не для контекста
 * модели.
 *
 * Второе, что здесь живёт: честный счётчик символов (promptChars). Ретривал
 * без потолка контекста — это способ однажды отправить в модель весь корпус.
 */
final class KnowledgeContext
{
    /**
     * @param  list<array{chunk: FaqChunk, score: float, bm25_score: float}>  $hits
     */
    public function __construct(
        public readonly array $hits,
        public readonly string $question = '',
    ) {}

    public static function empty(string $question = ''): self
    {
        return new self([], $question);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    public function count(): int
    {
        return count($this->hits);
    }

    /**
     * Лучший скор слияния (RRF у гибрида, BM25 у пола). Для денежных порогов
     * НЕ годится — там своя шкала, см. bestBm25Score().
     */
    public function bestScore(): float
    {
        return (float) ($this->hits[0]['score'] ?? 0.0);
    }

    /**
     * Лучший BM25-скор. Именно он сравнивается с support.faq_rag.min_score:
     * шкала RRF в сотни раз мельче, и порог в её домене молча пропускал бы
     * всё подряд (контракт H4001).
     */
    public function bestBm25Score(): float
    {
        return (float) ($this->hits[0]['bm25_score'] ?? $this->hits[0]['score'] ?? 0.0);
    }

    /**
     * Цитаты без текста раздела — то, что пишется в аудит и показывается
     * куратору. Порядок = порядок ранжирования.
     *
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, source: string, score: float, bm25_score: float}>
     */
    public function citations(): array
    {
        $out = [];
        foreach ($this->hits as $hit) {
            $out[] = [
                'chunk_id' => $hit['chunk']->chunkId,
                'title' => $hit['chunk']->title,
                'heading_path' => $hit['chunk']->headingPath,
                'source' => $hit['chunk']->source,
                'score' => round((float) $hit['score'], 4),
                'bm25_score' => round((float) $hit['bm25_score'], 4),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function chunkIds(): array
    {
        return array_map(
            static fn (array $hit): string => $hit['chunk']->chunkId,
            $this->hits,
        );
    }

    /**
     * Блок контекста для промпта: полный текст каждого раздела, разделённый
     * пустой строкой. Заголовочный путь идёт первой строкой раздела — по нему
     * модель понимает, о чём раздел, не читая тело.
     */
    public function promptBlock(): string
    {
        $parts = [];
        foreach ($this->hits as $hit) {
            $parts[] = $this->renderChunk($hit['chunk']);
        }

        return implode("\n\n", $parts);
    }

    public function promptChars(): int
    {
        return mb_strlen($this->promptBlock());
    }

    /**
     * Тот же блок, но обрезанный по потолку символов. Обрезка идёт ПО ГРАНИЦЕ
     * РАЗДЕЛА, а не по середине текста: половина раздела в промпте — это
     * приглашение модели договорить пропущенное.
     */
    public function promptBlockCapped(int $maxChars): string
    {
        if ($maxChars <= 0) {
            return $this->promptBlock();
        }

        $parts = [];
        $used = 0;
        foreach ($this->hits as $hit) {
            $rendered = $this->renderChunk($hit['chunk']);
            $len = mb_strlen($rendered);
            if ($parts !== [] && $used + $len > $maxChars) {
                break;
            }
            $parts[] = $rendered;
            $used += $len + 2;
        }

        // Первый раздел не влез в потолок — отдаём его целиком, а не пустоту:
        // пустой контекст отвечающая сторона читает как «данных нет».
        return $parts === [] ? $this->renderChunk($this->hits[0]['chunk']) : implode("\n\n", $parts);
    }

    private function renderChunk(FaqChunk $chunk): string
    {
        $heading = implode(' → ', $chunk->headingPath);

        return ($heading === '' ? '' : '## '.$heading."\n\n").trim($chunk->body);
    }
}
