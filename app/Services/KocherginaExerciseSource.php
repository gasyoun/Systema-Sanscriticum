<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Текст упражнений занятия учебника Кочергиной (H1764, D5/D6).
 *
 * Источник — оцифровка `Kochergina_unicode.mdx` в СОСЕДНЕМ клоне SanskritGrammar,
 * путь берётся из `homework.auto_open.source_path`. Текст 1998 года под охраной
 * авторского права: в этот публичный репозиторий он не копируется — ни сидером,
 * ни фикстурой (D6, следствие 1). Тесты работают на синтетическом фрагменте.
 *
 * Формат источника, установленный при аудите 27-07-2026: занятия помечены
 * строками `Занятие I` … `Занятие XL` (обычный текст, не разметка), блок
 * упражнений внутри занятия начинается строкой ровно `Упражнения` и тянется
 * до следующего заголовка занятия. Строка «Упражнения на повторение графики
 * и слов» — НЕ начало блока, поэтому сравнение строгое, а не по вхождению.
 *
 * Парсер сознательно осторожен: при любой неуверенности возвращает null, а не
 * мусор. null — не ошибка; открытие ДЗ продолжается с отсылочной формулировкой.
 */
class KocherginaExerciseSource
{
    /** Занятий в учебнике. Больше 40 — заведомо не наш номер. */
    public const MAX_LESSON = 40;

    /**
     * Строки источника, разобранные один раз на процесс: файл ~15 700 строк,
     * а за один прогон команды к нему обращаются до пяти раз.
     *
     * @var array<string, list<string>|null>
     */
    private static array $lines = [];

    public function forLesson(int $textbookLesson): ?string
    {
        if ($textbookLesson < 1 || $textbookLesson > self::MAX_LESSON) {
            return null;
        }

        $lines = $this->lines();
        if ($lines === null) {
            return null;
        }

        $start = $this->headingIndex($lines, $textbookLesson);
        if ($start === null) {
            Log::warning('KocherginaExerciseSource: заголовок занятия не найден', [
                'textbook_lesson' => $textbookLesson,
            ]);

            return null;
        }

        // Конец занятия — следующий заголовок ЛЮБОГО занятия, иначе конец файла.
        $end = count($lines);
        for ($i = $start + 1; $i < count($lines); $i++) {
            if ($this->romanOnLine($lines[$i]) !== null) {
                $end = $i;
                break;
            }
        }

        // Блок упражнений внутри занятия.
        $exercisesAt = null;
        for ($i = $start + 1; $i < $end; $i++) {
            if ($this->normalize($lines[$i]) === 'Упражнения') {
                $exercisesAt = $i;
                break;
            }
        }

        if ($exercisesAt === null) {
            Log::warning('KocherginaExerciseSource: блок «Упражнения» не найден', [
                'textbook_lesson' => $textbookLesson,
            ]);

            return null;
        }

        $block = trim(implode("\n", array_slice($lines, $exercisesAt + 1, $end - $exercisesAt - 1)));

        return $block === '' ? null : $block;
    }

    /**
     * Отсылочная формулировка — то, что подставляется вместо текста учебника:
     * когда источник недоступен (A8) и когда урок публичный (D14/A7).
     */
    public static function fallbackPrompt(?int $textbookLesson): string
    {
        return $textbookLesson === null
            ? 'Выполните все упражнения к этому занятию учебника Кочергиной.'
            : "Выполните все упражнения к Занятию {$textbookLesson} учебника Кочергиной.";
    }

    /** Сбросить кэш процесса — нужен тестам, меняющим путь источника. */
    public static function flush(): void
    {
        self::$lines = [];
    }

    /** @return list<string>|null */
    private function lines(): ?array
    {
        $path = (string) config('homework.auto_open.source_path');

        if ($path === '') {
            return null;
        }

        if (array_key_exists($path, self::$lines)) {
            return self::$lines[$path];
        }

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('KocherginaExerciseSource: источник недоступен', ['path' => $path]);

            return self::$lines[$path] = null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            Log::warning('KocherginaExerciseSource: источник не читается', ['path' => $path]);

            return self::$lines[$path] = null;
        }

        return self::$lines[$path] = preg_split('/\R/u', $raw) ?: null;
    }

    /**
     * Номер строки с заголовком «Занятие N», или null.
     *
     * @param  list<string>  $lines
     */
    private function headingIndex(array $lines, int $textbookLesson): ?int
    {
        foreach ($lines as $i => $line) {
            if ($this->romanOnLine($line) === $textbookLesson) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Номер занятия, если строка — заголовок занятия; иначе null.
     * Ведущие `#` и `>` markdown-разметки снимаются: оцифровка их не ставит,
     * но правка источника может, и заголовок от этого заголовком быть не перестаёт.
     */
    private function romanOnLine(string $line): ?int
    {
        $normalized = $this->normalize($line);

        if (! preg_match('/^Занятие\s+([IVXL]+)$/u', $normalized, $m)) {
            return null;
        }

        $number = $this->fromRoman($m[1]);

        return ($number >= 1 && $number <= self::MAX_LESSON) ? $number : null;
    }

    private function normalize(string $line): string
    {
        // NBSP и прочие пробельные из docx-конверсии + markdown-префиксы.
        $line = str_replace("\u{00A0}", ' ', $line);

        return trim(preg_replace('/^[>#\s]+/u', '', $line) ?? $line);
    }

    private function fromRoman(string $roman): int
    {
        $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50];
        $chars = str_split(strtoupper($roman));
        $total = 0;

        foreach ($chars as $i => $char) {
            $value = $map[$char] ?? 0;
            $next = isset($chars[$i + 1]) ? ($map[$chars[$i + 1]] ?? 0) : 0;
            $total += $value < $next ? -$value : $value;
        }

        return $total;
    }
}
