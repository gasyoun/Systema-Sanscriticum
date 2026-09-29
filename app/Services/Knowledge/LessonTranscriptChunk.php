<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Этап 4 — один фрагмент расшифровки урока: то, что уходит в эмбеддинг,
 * и то, что потом цитируется в ответе бота с таймкодом.
 *
 * Сознательно НЕ наследует FaqChunk (тот final и описывает раздел файла
 * корпуса): у урока нет заголовков, зато есть урок, курс и секунда начала.
 */
final class LessonTranscriptChunk
{
    public function __construct(
        public readonly string $chunkId,
        public readonly int $lessonId,
        public readonly string $courseId,
        public readonly int $startSeconds,
        public readonly string $timecode,
        public readonly string $heading,
        public readonly string $text,
    ) {}

    /**
     * Текст для эмбеддинга: шапка с курсом, уроком, датой и таймкодом плюс сам
     * фрагмент. Шапка нужна, потому что в дословной речи почти никогда не
     * звучит ни название курса, ни номер занятия — без неё вопрос «что было на
     * пятнадцатом занятии» не находит ничего.
     */
    public function searchText(): string
    {
        return trim($this->heading.', '.$this->timecode."\n".$this->text);
    }

    /** Короткая выдержка для цитаты в ответе (H3308: не вся лекция). */
    public function quote(int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->text) ?? $this->text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars)).'…';
    }
}
