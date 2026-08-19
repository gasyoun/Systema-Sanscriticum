<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Путь под `storage/`, не разделяемый между параллельными тест-воркерами.
 *
 * Зачем. CI гоняет `php artisan test --parallel`: несколько процессов делят
 * ОДИН `storage/`. Сервис, который пишет в фиксированное место
 * (`storage/app/grammar_lab_sample.json`, `storage/app/visualdcs/releases/...`),
 * под параллелью получает гонку: один воркер перезаписывает файл, пока другой
 * его читает. Симптом безобиден на вид и максимально сбивает с толку —
 * `json_decode` возвращает null, тест падает на `$manifest['...']`, причём
 * в PR, который этого кода вообще не касался.
 *
 * Так упали два раза за 19-08-2026: `GrammarLabLearningLoopTest` на
 * debt-reminders-PR и `VisualDcsSurfacesTest` на PR из двух markdown-файлов.
 * Оба зеленели на re-run — и это ровно та форма, в которой настоящая гонка
 * притворяется «просто флаки».
 *
 * Локально `php artisan test` (без `--parallel`) такой дефект не показывает
 * НИКОГДА: воркер один. Проверять фикс нужно параллельным прогоном.
 *
 * В проде и в однопроцессном прогоне возвращает обычный `storage_path()` —
 * `TEST_TOKEN` выставляет только ParaTest.
 */
final class ParallelSafePath
{
    /**
     * @param  string  $relative  путь относительно `storage/`, например `app/grammar_lab_sample.json`
     */
    public static function storage(string $relative): string
    {
        $relative = ltrim($relative, '/\\');
        $token = self::token();

        return $token === null
            ? storage_path($relative)
            : storage_path('parallel/'.$token.'/'.$relative);
    }

    /** Номер параллельного воркера ParaTest, либо null вне параллельного прогона. */
    public static function token(): ?string
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false) {
            $token = $_ENV['TEST_TOKEN'] ?? null;
        }

        $token = is_scalar($token) ? trim((string) $token) : '';

        // Только цифры/буквы — токен идёт в путь, и подставлять туда
        // произвольную строку из окружения не нужно.
        return $token !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $token) === 1
            ? $token
            : null;
    }
}
