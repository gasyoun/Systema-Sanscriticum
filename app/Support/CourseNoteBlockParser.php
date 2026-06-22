<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Распознаёт номера блоков входа/выхода из свободного текста примечания к курсу
 * (pivot course_user.note). Исторически админы писали границы прямо в текст
 * («вошёл с 3 блока», «ушёл после 5»), а структурные колонки joined_at_block /
 * left_after_block оставались пустыми — этот парсер вытаскивает их обратно.
 *
 * Логика: находим числа-кандидаты (рядом со словом «блок» либо сразу после
 * предлога направления — «после 4»), а направление каждого числа определяем по
 * управляющему слову СЛЕВА от него («с блока 2» → вход, «после 4» → выход). Так
 * «с блока 2, вышел после 4» корректно даёт вход=2, выход=4. Формат произвольный,
 * поэтому результат — лишь кандидаты: финальное решение за человеком
 * (предпросмотр-подтверждение в админке).
 */
final class CourseNoteBlockParser
{
    /** Слово «блок» в любой форме/сокращении: блок, блока, блоков, бл, бл. */
    private const BLOCK = '(?:блок\w*|бл\.?)';

    /** Маркеры входа (слева от числа). */
    private const ENTRY_WORDS = ['вход', 'вошёл', 'вошел', 'пришёл', 'пришел', 'присоедин', 'начал', 'старт', 'с '];

    /** Маркеры выхода (слева от числа). */
    private const EXIT_WORDS = ['выход', 'вышел', 'ушёл', 'ушел', 'покинул', 'отчислен', 'выпуск', 'после', 'до ', 'перед '];

    /** Сколько байт текста слева от числа считаем «управляющим контекстом». */
    private const LEFT_WINDOW = 60;

    /**
     * @return array{entry: ?int, exit: ?int}
     */
    public static function parse(?string $note): array
    {
        $lower = mb_strtolower(trim((string) $note), 'UTF-8');
        if ($lower === '') {
            return ['entry' => null, 'exit' => null];
        }

        $entry = null;
        $exit = null;

        // Кандидаты идут по позиции в тексте; первое уверенное совпадение каждого
        // направления выигрывает.
        foreach (self::candidates($lower) as $cand) {
            $dir = self::direction($lower, $cand['pos']);

            if ($dir === 'entry' && $entry === null) {
                $entry = $cand['num'];
            } elseif ($dir === 'exit' && $exit === null) {
                $exit = $cand['num'];
            }
        }

        return ['entry' => $entry, 'exit' => $exit];
    }

    /**
     * Числа-кандидаты с байтовой позицией, отсортированные по позиции.
     *
     * @return list<array{num: int, pos: int}>
     */
    private static function candidates(string $lower): array
    {
        $b = self::BLOCK;
        $out = [];

        // (?<!\d)…(?!\d) — число не должно быть частью длинного (деньги/год): «2000».
        $n = '(?<!\d)(\d{1,3})(?!\d)';

        $patterns = [
            '/'.$n.'\s*'.$b.'/u',                                  // «3 блока»
            '/'.$b.'\s*(?:№\s*)?'.$n.'/u',                         // «блок №3»
            '/(?<![а-яё])(?:после|перед|до|с)\s+'.$n.'/u',         // «после 4» — предлог + голое число
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $lower, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $hit) {
                    $out[$hit[1]] = ['num' => (int) $hit[0], 'pos' => (int) $hit[1]];
                }
            }
        }

        ksort($out); // по байтовой позиции числа

        return array_values($out);
    }

    /**
     * Направление числа по управляющему слову слева от него: чьё ключевое слово
     * ближе к числу (правее в левом контексте), то и побеждает.
     */
    private static function direction(string $lower, int $pos): ?string
    {
        $start = max(0, $pos - self::LEFT_WINDOW);
        $ctx = substr($lower, $start, $pos - $start);

        $entryAt = self::nearestMarker($ctx, self::ENTRY_WORDS);
        $exitAt = self::nearestMarker($ctx, self::EXIT_WORDS);

        if ($entryAt === null && $exitAt === null) {
            return null;
        }

        return ($exitAt ?? -1) > ($entryAt ?? -1) ? 'exit' : 'entry';
    }

    /**
     * Максимальная (ближайшая к числу) байтовая позиция любого из слов в контексте.
     *
     * @param  list<string>  $words
     */
    private static function nearestMarker(string $ctx, array $words): ?int
    {
        $best = null;
        foreach ($words as $word) {
            $at = strrpos($ctx, $word);
            if ($at !== false && ($best === null || $at > $best)) {
                $best = $at;
            }
        }

        return $best;
    }
}
