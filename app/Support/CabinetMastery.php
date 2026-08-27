<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H3215 — банк и проверка внутренних тестов кабинета.
 *
 * Ключи вариантов стабильны; порядок на экране перемешивается
 * детерминированно по (audience, user, question), чтобы «всегда первый»
 * не был стратегией.
 */
final class CabinetMastery
{
    public const AUDIENCE_CURATOR = 'curator';

    public const AUDIENCE_STUDENT = 'student';

    public const AUDIENCE_TEACHER = 'teacher';

    public const AUDIENCE_ACCOUNTANT = 'accountant';

    /**
     * @return array{title:string, intro:string, pass:int, questions:list<array<string, mixed>>}
     */
    public static function bank(string $audience): array
    {
        $bank = config('cabinet_mastery.'.$audience);
        if (! is_array($bank) || ! isset($bank['questions']) || ! is_array($bank['questions'])) {
            throw new \InvalidArgumentException('Unknown cabinet mastery audience: '.$audience);
        }

        return [
            'title' => (string) ($bank['title'] ?? 'Проверка'),
            'intro' => (string) ($bank['intro'] ?? ''),
            'pass' => (int) ($bank['pass'] ?? 0),
            'questions' => array_values($bank['questions']),
        ];
    }

    /**
     * Вопросы с перемешанным порядком вариантов. Ключи options те же.
     *
     * @return list<array<string, mixed>>
     */
    public static function questionsForDisplay(string $audience, int $userId): array
    {
        $out = [];
        foreach (self::bank($audience)['questions'] as $question) {
            $out[] = self::withShuffledOptions($question, $audience, $userId);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public static function withShuffledOptions(array $question, string $audience, int $userId): array
    {
        $options = $question['options'] ?? [];
        if (! is_array($options) || $options === []) {
            return $question;
        }

        $keys = array_keys($options);
        $seed = $audience.'|'.(string) $question['id'].'|'.$userId;
        usort($keys, function (string|int $a, string|int $b) use ($seed): int {
            return strcmp(
                hash('sha256', $seed.'|'.$a),
                hash('sha256', $seed.'|'.$b),
            );
        });

        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[(string) $key] = $options[$key];
        }
        $question['options'] = $shuffled;

        return $question;
    }

    /**
     * @param  array<string, string>  $answers  question id => option key
     * @return array{score:int, total:int, passed:bool, pass:int, details:list<array<string, mixed>>}
     */
    public static function grade(string $audience, array $answers): array
    {
        $bank = self::bank($audience);
        $details = [];
        $score = 0;

        foreach ($bank['questions'] as $question) {
            $id = (string) $question['id'];
            $correct = (string) $question['correct'];
            $given = isset($answers[$id]) ? (string) $answers[$id] : '';
            $ok = $given !== '' && $given === $correct;
            if ($ok) {
                $score++;
            }
            $details[] = [
                'id' => $id,
                'prompt' => $question['prompt'],
                'ok' => $ok,
                'given' => $given,
                'correct' => $correct,
                'why' => (string) ($question['why'] ?? ''),
            ];
        }

        $total = count($bank['questions']);
        $pass = (int) $bank['pass'];

        return [
            'score' => $score,
            'total' => $total,
            'pass' => $pass,
            'passed' => $total > 0 && $score >= $pass,
            'details' => $details,
        ];
    }
}
