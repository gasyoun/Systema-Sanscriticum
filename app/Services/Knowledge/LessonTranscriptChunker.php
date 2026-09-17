<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Lesson;
use App\Support\TranscriptParser;

/**
 * Этап 4 — режет расшифровку урока на фрагменты под эмбеддинг.
 *
 * Границы предложений НЕ пересчитываются: берём их у TranscriptParser (слово,
 * оканчивающееся на .!?), как это делают и нарезка клипов, и черновики FAQ.
 * Окна набираются до config('knowledge.lesson.chunk_chars') с перекрытием в
 * одно предложение — без перекрытия мысль рвётся по границе окна и ответ
 * теряет вторую половину правила (проверено на живой расшифровке 16-09-2026:
 * 866 предложений урока дают 69 окон по ~700 знаков).
 *
 * chunk_id стабилен между прогонами: `lesson-<id>/<секунда начала>`. Пока текст
 * фрагмента не изменился, content_hash тот же и повторная индексация не пишет
 * ничего.
 */
final class LessonTranscriptChunker
{
    /** @return list<LessonTranscriptChunk> */
    public function chunks(Lesson $lesson, ?int $maxChars = null): array
    {
        $sentences = TranscriptParser::sentencesFromStoredFile($lesson->transcript_file);
        if ($sentences === []) {
            return [];
        }

        $maxChars = max(200, $maxChars ?? (int) config('knowledge.lesson.chunk_chars', 700));
        $heading = $this->heading($lesson);
        $courseId = (string) $lesson->course_id;

        $chunks = [];
        $window = [];
        $length = 0;

        foreach ($sentences as $sentence) {
            $window[] = $sentence;
            $length += mb_strlen((string) ($sentence['text'] ?? ''));

            if ($length >= $maxChars) {
                $chunks[] = $this->makeChunk($lesson, $courseId, $heading, $window);
                $tail = $window[count($window) - 1];
                $window = [$tail];
                $length = mb_strlen((string) ($tail['text'] ?? ''));
            }
        }

        // Хвост забираем, только если в нём есть что-то кроме перекрытия —
        // иначе последнее предложение попало бы в индекс дважды.
        if (count($window) > 1 || $chunks === []) {
            $chunks[] = $this->makeChunk($lesson, $courseId, $heading, $window);
        }

        return $chunks;
    }

    /**
     * @param  list<array{formatted_time: string, start: float, end: float, text: string, safe_text: string}>  $window
     */
    private function makeChunk(Lesson $lesson, string $courseId, string $heading, array $window): LessonTranscriptChunk
    {
        $first = $window[0];
        $start = (int) floor((float) ($first['start'] ?? 0));
        $text = trim(implode(' ', array_map(
            static fn (array $sentence): string => trim((string) ($sentence['text'] ?? '')),
            $window,
        )));

        return new LessonTranscriptChunk(
            chunkId: 'lesson-'.$lesson->id.'/'.$start,
            lessonId: (int) $lesson->id,
            courseId: $courseId,
            startSeconds: $start,
            timecode: (string) ($first['formatted_time'] ?? self::formatTimecode($start)),
            heading: $heading,
            text: $text,
        );
    }

    /** Та же формула, что в TranscriptParser::makeSentence (она private). */
    private static function formatTimecode(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    private function heading(Lesson $lesson): string
    {
        $parts = array_filter([
            $lesson->course?->title,
            $lesson->title,
            $lesson->lesson_date?->format('d.m.Y'),
        ]);

        return implode(' · ', $parts);
    }
}
