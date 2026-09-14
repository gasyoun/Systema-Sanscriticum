<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StoryPost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Кураторский источник «харвест-медиа → сториз» (H3964, юнит 5).
 *
 * Без DM-автоимпорта: куратор сам выбирает файл из OUT-OF-GIT raw-стора
 * харвеста (записи с непустым media_local_path — медиа скачаны только для
 * media_download_peers) и заводит черновик story_posts persona-полосы.
 * Публикация — как у всех: approve + publish_at + флаги.
 *
 *   stories:from-harvest --list [--limit=15]      перепись свежих медиа
 *   stories:from-harvest <путь> --caption="..."   заводит черновик
 *
 * Дедуп — source_key = md5(пути): повторный вызов с тем же файлом молча
 * пропускается, черновик не дублируется.
 */
final class StoriesFromHarvestCommand extends Command
{
    protected $signature = 'stories:from-harvest
        {path? : Абсолютный путь к медиа-файлу из harvest-стора (media_local_path)}
        {--list : Переписать свежие harvest-медиа (media_local_path) и выйти}
        {--limit=15 : Сколько строк показывать в --list}
        {--caption= : Подпись к сториз}
        {--publish-at= : Когда публиковать (Y-m-d H:i), иначе завтра в default_publish_hour}
        {--kind= : Переопределить тип (photo|video), иначе из расширения}';

    protected $description = 'Create a persona-lane story_post draft from a harvest media file (curator-picked, no DM auto-import)';

    private const PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    private const VIDEO_EXT = ['mp4', 'mov', 'm4v'];

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listHarvestMedia(max(1, (int) $this->option('limit')));
        }

        $path = (string) $this->argument('path');
        if ($path === '') {
            $this->error('Укажите путь к медиа-файлу (см. --list) или запустите --list.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Файл не найден или не читается: {$path}");

            return self::FAILURE;
        }

        $kind = (string) ($this->option('kind') ?: $this->kindFromExtension($path));
        if (! in_array($kind, [StoryPost::KIND_PHOTO, StoryPost::KIND_VIDEO], true)) {
            $this->error("Не распознал тип файла по расширению ({$path}) — задайте явно --kind=photo|video.");

            return self::FAILURE;
        }

        $sourceKey = 'harvest:'.md5($path);
        $exists = StoryPost::query()
            ->where('source', StoryPost::SOURCE_HARVEST)
            ->where('source_key', $sourceKey)
            ->exists();
        if ($exists) {
            $this->warn("Этот файл уже заведён в очередь (source_key={$sourceKey}) — пропуск.");

            return self::SUCCESS;
        }

        $post = StoryPost::query()->create([
            'kind' => $kind,
            'lane' => StoryPost::LANE_PERSONA,
            'payload' => (string) $this->option('caption'),
            'media_path' => $path,
            'source' => StoryPost::SOURCE_HARVEST,
            'source_key' => $sourceKey,
            'status' => StoryPost::STATUS_DRAFT,
            'publish_at' => $this->resolvePublishAt(),
            'journal' => now()->toDateTimeString().' source: harvest-медиа, отобрано куратором (stories:from-harvest).',
        ]);

        $this->info("Создан черновик #{$post->id} (kind={$kind}, due {$post->publish_at?->format('d-m-Y H:i')}) — далее: approve в Filament.");

        return self::SUCCESS;
    }

    /**
     * Перепись свежих медиа raw-стора: рекурсивный проход *.jsonl
     * ({store}/{lane}/{peerKey}/{YYYY-MM-DD}.jsonl), строки с непустым
     * media_local_path, новейшие сверху. Стор только ЧИТАЕТСЯ.
     */
    private function listHarvestMedia(int $limit): int
    {
        $store = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        if (! is_dir($store)) {
            $this->error("Харвест-стор не найден: {$store} (services.telegram_harvest.store_path).");

            return self::FAILURE;
        }

        $found = [];
        // glob() на Windows не понимает обратные слэши в паттерне — нормализуем.
        $pattern = str_replace('\\', '/', $store).'/*/*/*.jsonl';
        foreach (File::glob($pattern) as $file) {
            foreach (File::lines($file) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                $mediaPath = (string) ($record['media_local_path'] ?? '');
                if ($mediaPath === '' || ! is_file($mediaPath)) {
                    continue;
                }
                $found[] = [
                    'sent_at' => (string) ($record['sent_at'] ?? ''),
                    'peer' => (string) ($record['peer'] ?? '?'),
                    'path' => $mediaPath,
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => strcmp($b['sent_at'], $a['sent_at']));

        if ($found === []) {
            $this->warn('Медиа с media_local_path в сторе не найдено (медиа качаются только для media_download_peers).');

            return self::SUCCESS;
        }

        $this->table(['sent_at', 'peer', 'media_local_path'], array_slice($found, 0, $limit));
        $this->info('Всего с путями: '.count($found).'. Заводить: stories:from-harvest <путь> --caption="…".');

        return self::SUCCESS;
    }

    private function kindFromExtension(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, self::PHOTO_EXT, true) => StoryPost::KIND_PHOTO,
            in_array($ext, self::VIDEO_EXT, true) => StoryPost::KIND_VIDEO,
            default => '',
        };
    }

    private function resolvePublishAt(): Carbon
    {
        $explicit = (string) $this->option('publish-at');
        if ($explicit !== '') {
            return Carbon::parse($explicit, config('app.timezone'));
        }

        $hour = max(0, min(23, (int) config('services.telegram_story.default_publish_hour', 9)));

        return Carbon::tomorrow(config('app.timezone'))->setHour($hour)->setMinute(0)->setSecond(0);
    }
}
