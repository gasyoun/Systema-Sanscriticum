<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseQuiz;
use App\Models\CourseQuizQuestion;

/**
 * Квиз этапа курса: показ и проверка.
 *
 * Порядок вопросов и вариантов перемешивается детерминированно по
 * (quiz, question, user) — «всегда первый вариант» не является стратегией,
 * но результат проверки воспроизводим без хранения порядка на сервере.
 */
final class CourseStageQuiz
{
    /**
     * Вопросы для показа: перемешанные варианты под конкретного студента.
     *
     * @return list<array{id: int, prompt: string, options: array<int, string>, explanation: ?string}>
     */
    public static function questionsForDisplay(CourseQuiz $quiz, int $userId): array
    {
        return $quiz->questions->map(fn (CourseQuizQuestion $question) => [
            'id' => $question->id,
            'prompt' => $question->question,
            'options' => self::shuffledOptions($question, $userId),
            'explanation' => $question->explanation,
        ])->all();
    }

    /**
     * Варианты вопроса в детерминированном для студента порядке.
     * Ключи — исходные индексы, поэтому проверка не зависит от перемешивания.
     *
     * @return array<int, string>
     */
    public static function shuffledOptions(CourseQuizQuestion $question, int $userId): array
    {
        $options = (array) $question->options;
        $keys = array_keys($options);
        $seed = 'quiz|'.$question->course_quiz_id.'|'.$question->id.'|'.$userId;

        usort($keys, fn (int|string $a, int|string $b): int => strcmp(
            hash('sha256', $seed.'|'.$a),
            hash('sha256', $seed.'|'.$b),
        ));

        $out = [];
        foreach ($keys as $key) {
            $out[(int) $key] = (string) $options[$key];
        }

        return $out;
    }

    /**
     * Проверка ответов: {'<question_id>': '<индекс варианта>'}.
     *
     * @param  array<string, mixed>  $answers
     * @return array{score: int, total: int, passed: bool, pass: int, details: list<array{id: int, ok: bool, why: ?string}>}
     */
    public static function grade(CourseQuiz $quiz, array $answers): array
    {
        $score = 0;
        $details = [];
        $questions = $quiz->questions;

        foreach ($questions as $question) {
            $given = $answers[$question->id] ?? null;
            $ok = is_numeric($given) && (int) $given === $question->correct_option;
            if ($ok) {
                $score++;
            }
            $details[] = [
                'id' => $question->id,
                'ok' => $ok,
                'why' => $ok
                    ? $question->explanation
                    : trim(($question->explanation ? $question->explanation.' ' : '')
                        .'Правильный ответ: «'.$question->correctOptionText().'».'),
            ];
        }

        $total = $questions->count();
        $passPercent = (int) $quiz->pass_score;
        $percent = $total > 0 ? (int) round(($score / $total) * 100) : 0;

        return [
            'score' => $score,
            'total' => $total,
            'passed' => $percent >= $passPercent,
            'pass' => $passPercent,
            'percent' => $percent,
            'details' => $details,
        ];
    }
}
