<?php

declare(strict_types=1);

/**
 * Фикстура для MadelineSyncGuardsTest: заход, зависший РОВНО в той форме, в
 * которой watchdog отказывал на проде 28.07.2026 (H1915) — работа идёт в
 * callback'ах событийного цикла, а ошибки цикла проглатывает обработчик
 * (MadelineProto ставит именно такой: логирует и продолжает).
 *
 * Прежняя версия watchdog'а бросала исключение и на этой форме НЕ убивала заход:
 * исключение уходило в error handler, а одноразовый pcntl_alarm был уже потрачен.
 * Отдельный процесс нужен потому, что проверяемое поведение — exit() процесса.
 *
 * Запуск: php watchdog_hung_run.php <timeout_seconds> [cleanup_marker_path]
 * Ожидание: смерть за ~timeout с кодом MadelineSyncWatchdog::EXIT_TIMED_OUT (75).
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Telegram\MadelineSyncWatchdog;
use Revolt\EventLoop;

$timeout = (int) ($argv[1] ?? 1);
$marker = $argv[2] ?? null;

$watchdog = new MadelineSyncWatchdog;

$armed = $watchdog->arm($timeout, static function (int $seconds) use ($marker): void {
    if ($marker !== null) {
        file_put_contents($marker, "cleanup:{$seconds}");
    }
});

if (! $armed) {
    fwrite(STDERR, "watchdog не взвёлся\n");
    exit(2);
}

// Обработчик ошибок цикла, который ЛОГИРУЕТ И ПРОДОЛЖАЕТ — как AbstractAPI.php:41.
EventLoop::setErrorHandler(static fn (Throwable $e) => null);

EventLoop::repeat(0.01, static function (): void {
    $end = microtime(true) + 0.04;
    while (microtime(true) < $end) {
        // Занятая работа внутри callback'а: сигнал прилетает сюда, а не на
        // собственный стек драйвера.
    }
});

// Заведомо дольше потолка: без работающего watchdog'а процесс доживёт до конца.
EventLoop::delay(60, static fn () => EventLoop::getDriver()->stop());
EventLoop::run();

// Управление сюда попадать не должно — это и есть провал теста.
fwrite(STDERR, "заход пережил потолок {$timeout} с\n");
exit(0);
