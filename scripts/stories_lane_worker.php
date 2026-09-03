<?php

declare(strict_types=1);
use App\Services\Stories\StoryPublisher;
use Illuminate\Contracts\Console\Kernel;

/**
 * Сториз-воркер H3964: ИЗОЛИРОВАННЫЙ процесс для MTProto-вызовов MadelineProto.
 *
 * Зачем отдельный процесс: из-под artisan второй заход в Amp-цикл
 * MadelineProto v8 ронял процесс с Revolt DriverSuspension («Event loop
 * terminated without resuming the current suspension», живой замер
 * 03-09-2026), а тот же самый код в standalone-процессе работает чисто
 * (send+delete подряд, ни одного подвешенного фибера). stories:publish-story
 * поэтому держит madeline-session-лок, очередь, гейты и журнал У СЕБЯ, а
 * каждый MTProto-вызов исполняет этим воркером.
 *
 * Контракт: argv[1] — JSON задачи
 *   {"action":"send_photo","path":"...","caption":"..."}
 *   {"action":"send_video","path":"...","caption":"..."}
 *   {"action":"delete","story_id":123}
 * stdout — ровно ОДНА строка JSON:
 *   {"ok":true,"story_id":123} | {"ok":false,"error":"..."}
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function fail(string $message): never
{
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}

$task = json_decode((string) ($argv[1] ?? ''), true);
if (! is_array($task) || ! isset($task['action'])) {
    fail('invalid task json');
}

/** @var StoryPublisher $publisher */
$publisher = app(StoryPublisher::class);

try {
    $result = match ($task['action']) {
        'send_photo' => ['ok' => true, 'story_id' => $publisher->sendPhotoStoryDirect(
            (string) ($task['path'] ?? ''),
            (string) ($task['caption'] ?? ''),
        )],
        'send_video' => ['ok' => true, 'story_id' => $publisher->sendVideoStoryDirect(
            (string) ($task['path'] ?? ''),
            (string) ($task['caption'] ?? ''),
        )],
        'delete' => tap(['ok' => true, 'story_id' => null], function () use ($publisher, $task): void {
            $publisher->deleteStoryDirect((int) ($task['story_id'] ?? 0));
        }),
        default => fail("unknown action {$task['action']}"),
    };
} catch (Throwable $e) {
    fail(substr($e->getMessage(), 0, 500));
}

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE).PHP_EOL);
exit(0);
