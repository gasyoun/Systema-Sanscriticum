<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Разовая миграция H3310: сертификатные ZIP-архивы уезжают с публичного
 * диска в приватный storage/app/archives.
 *
 * Идемпотентна: файл, уже существующий на приватном диске, пропускается,
 * так что повторный запуск и обрыв на середине безопасны. Прямая ссылка
 * /storage/archives/... после переноса отдаёт 404, скачивание остаётся
 * только через staff-маршрут force-download.
 */
class MovePublicArchivesToLocal extends Command
{
    protected $signature = 'archives:move-public-to-local {--dry-run : Показать план, ничего не переносить}';

    protected $description = 'Переносит сертификатные ZIP из storage/app/public/archives на приватный диск (storage/app/archives)';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        if (! $public->directoryExists('archives')) {
            $this->info('Каталога public/archives нет — переносить нечего.');

            return self::SUCCESS;
        }

        /** @var list<string> $files */
        $files = $public->files('archives');

        if ($files === []) {
            $this->info('На публичном диске архивов нет — переносить нечего.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;

        foreach ($files as $path) {
            if (! preg_match('/^archives\/[A-Za-z0-9._-]+$/', $path)) {
                $this->warn("Пропущен неожиданный путь: {$path}");

                continue;
            }

            if ($local->exists($path)) {
                $skipped++;
                $this->line("Уже на месте: {$path}");

                continue;
            }

            if ((bool) $this->option('dry-run')) {
                $moved++;
                $this->line("Будет перенесён: {$path}");

                continue;
            }

            $stream = $public->readStream($path);

            if (! is_resource($stream)) {
                $this->error("Не удалось открыть: {$path}");

                continue;
            }

            $local->writeStream($path, $stream);
            fclose($stream);

            if ($local->exists($path) && $local->size($path) === $public->size($path)) {
                $public->delete($path);
                $moved++;
                $this->info("Перенесён: {$path}");
            } else {
                $this->error("Копия не сошлась по размеру, оригинал не тронут: {$path}");
            }
        }

        if (! (bool) $this->option('dry-run') && ! $public->exists('archives/.gitignore') && $public->files('archives') === []) {
            $public->deleteDirectory('archives');
            $this->info('Каталог public/archives опустел и удалён.');
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            $dryRun ? 'План (--dry-run): будет перенесено %d, уже на месте %d.' : 'Итог: перенесено %d, уже было на месте %d.',
            $moved,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
