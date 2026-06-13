<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanCourseArchives extends Command
{
    protected $signature = 'archives:cleanup {--hours=24 : Удалять архивы старше N часов}';

    protected $description = 'Удаляет старые ZIP-архивы: материалы курсов (tmp/course-archives) и сертификаты группы (public/archives)';

    public function handle(): int
    {
        // Два независимых каталога ZIP-архивов. Сертификатные архивы кладёт
        // GenerateCertificatesArchive в public/archives и раньше не чистились —
        // копились бессрочно. Чистим оба по одному порогу --hours.
        $dirs = [
            storage_path('app/tmp/course-archives'),
            storage_path('app/public/archives'),
        ];

        $threshold = now()->subHours((int) $this->option('hours'))->timestamp;
        $deleted = 0;
        $freedBytes = 0;

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.zip') as $file) {
                if (filemtime($file) < $threshold) {
                    $freedBytes += filesize($file) ?: 0;
                    if (@unlink($file)) {
                        $deleted++;
                    }
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
