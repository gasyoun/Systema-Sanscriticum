<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anons\VideoAnons\VideoAnonsService;
use Illuminate\Console\Command;

/**
 * H6546: офлайн-канарейка лейна — повтор существующего сообщения через ТОТ ЖЕ
 * путь, что и live-вебхук (VideoAnonsService::handle → DistributeVideoAnonsJob).
 *
 *   video-anons:replay --payload=/tmp/1067.json
 *
 * --payload — JSON-файл с массивом message (или полным update с ключом
 * message); дедуп лейна (TelegramSendGuard) действует и здесь: повторный
 * replay того же сообщения подавляется, как и повтор вебхука.
 */
final class VideoAnonsReplayCommand extends Command
{
    protected $signature = 'video-anons:replay
        {--payload= : Путь к JSON-файлу с message (или полным update)}';

    protected $description = 'Replay a stored Telegram message through the video-anons lane (offline canary, H6546)';

    public function handle(): int
    {
        $path = (string) $this->option('payload');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->error("Payload не найден или не читается: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->error('Payload не разбирается как JSON-объект.');

            return self::FAILURE;
        }

        $message = $data['message'] ?? $data;
        foreach (['chat.id', 'message_id'] as $path) {
            if (data_get($message, $path) === null) {
                $this->error("В payload нет {$path} — это не message Telegram.");

                return self::FAILURE;
            }
        }

        $course = app(VideoAnonsService::class)->courseForChat((int) data_get($message, 'chat.id'));
        if ($course === null) {
            $this->error('Чат вне карты курс→чаты (config/video-anons.php) — лейн молчит и на live.');

            return self::FAILURE;
        }

        app(VideoAnonsService::class)->handle($message);

        $this->info(sprintf(
            'Replay отправлен в лейн: чат %d, сообщение %d, курс %s, цели %s (очередь обработает DistributeVideoAnonsJob).',
            (int) data_get($message, 'chat.id'),
            (int) data_get($message, 'message_id'),
            $course['key'],
            implode(', ', array_diff($course['chat_ids'], [(int) data_get($message, 'chat.id')])),
        ));

        return self::SUCCESS;
    }
}
