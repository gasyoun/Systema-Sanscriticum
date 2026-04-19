<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanCourseArchives extends Command
{
    protected $signature = 'archives:cleanup {--hours=24 : Удалять архивы старше N часов}';
    protected $description = 'Удаляет старые ZIP-архивы материалов курсов из storage/app/tmp/course-archives';

    public function handle(): int
    {
        $dir = storage_path('app/tmp/course-archives');

        if (!is_dir($dir)) {
            $this->info('Директория архивов не существует — нечего чистить.');
            return self::SUCCESS;
        }

        $threshold = now()->subHours((int) $this->option('hours'))->timestamp;
        $deleted = 0;
        $freedBytes = 0;

        foreach (glob($dir . '/*.zip') as $file) {
            if (filemtime($file) < $threshold) {
                $freedBytes += filesize($file) ?: 0;
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        $this->info(sprintf(
            'Удалено архивов: %d, освобождено: %s МБ',
            $deleted,
            round($freedBytes / 1024 / 1024, 2)
        ));

        return self::SUCCESS;
    }
}