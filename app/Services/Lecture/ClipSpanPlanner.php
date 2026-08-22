<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\Lesson;
use App\Support\TranscriptParser;

/**
 * Превращает уже существующие AI-таймкоды лекции (TranscriptParser, те же
 * данные, что верифицирует LectureAiClient::verifyTimecodes /
 * RunLectureAiJob 'timecodes') в кандидатные спаны для нарезки клипов
 * (H1452, Wave 4). НЕ пересчитывает границы — только группирует уже
 * верифицированные предложения-таймкоды в отрезки целевой длительности.
 */
final class ClipSpanPlanner
{
    /**
     * @return list<array{start_seconds:int,end_seconds:int,title:string}>
     */
    public static function planSpans(Lesson $lesson, int $targetSeconds = 90, int $maxSpans = 12): array
    {
        $sentences = TranscriptParser::sentencesFromStoredFile($lesson->transcript_file);
        if (empty($sentences)) {
            return [];
        }

        $spans = [];
        $spanStart = null;
        $spanEnd = null;
        $spanText = '';

        foreach ($sentences as $sentence) {
            if ($spanStart === null) {
                $spanStart = (int) $sentence['start'];
            }
            $spanEnd = (int) $sentence['end'];
            $spanText .= ($spanText === '' ? '' : ' ').$sentence['text'];

            if ($spanEnd - $spanStart >= $targetSeconds) {
                $spans[] = self::makeSpan($lesson, $spanStart, $spanEnd, $spanText, count($spans) + 1);
                $spanStart = null;
                $spanEnd = null;
                $spanText = '';

                if (count($spans) >= $maxSpans) {
                    break;
                }
            }
        }

        // Хвост короче $targetSeconds всё равно достоин отдельного клипа, если
        // набрался хоть какой-то текст и лимит спанов ещё не исчерпан.
        if ($spanStart !== null && $spanEnd !== null && count($spans) < $maxSpans) {
            $spans[] = self::makeSpan($lesson, $spanStart, $spanEnd, $spanText, count($spans) + 1);
        }

        return $spans;
    }

    /**
     * @return array{start_seconds:int,end_seconds:int,title:string}
     */
    private static function makeSpan(Lesson $lesson, int $start, int $end, string $text, int $index): array
    {
        $base = $lesson->topic ?: $lesson->title;
        $snippet = mb_substr(trim($text), 0, 60);

        return [
            'start_seconds' => $start,
            'end_seconds' => $end,
            'title' => trim("{$base} — фрагмент {$index}: {$snippet}"),
        ];
    }
}
