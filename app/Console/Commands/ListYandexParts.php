<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Свежепроцессный листинг каталога off-site диска: сабра держит один
 * персистентный curl-хендл на процесс, и флап этого хендла (прод 12-10-2026,
 * H6540/H6556: sabre-листинг stall 19:00–19:20 UTC при живом raw-curl
 * PROPFIND 207) ронял листинг докатки целиком. Один короткий процесс — один
 * свежий хендл — один Depth-1 PROPFIND (имена + размеры из одного ответа).
 * Вызывается из SplitUploadToYandex::resumePendingGroups() как subprocess
 * (по образцу backup:verify-yandex-part); sabre остаётся только на записи.
 *
 * Вывод: по строке на файл `basename\tsize`, size в байтах. Кривые креды /
 * мёртвая сеть → stderr + exit 1 в пределах таймаута, без зависания.
 */
class ListYandexParts extends Command
{
    protected $signature = 'backup:list-yandex-parts
                            {dir : Directory to list inside the disk}
                            {--disk=yandex_disk : Off-site disk name}';

    protected $description = 'Fresh-process listing of an off-site backup directory (basename<TAB>size per line)';

    public function handle(): int
    {
        $dir = (string) $this->argument('dir');
        $diskName = (string) $this->option('disk');

        try {
            $disk = Storage::disk($diskName);

            foreach ($disk->listContents($dir) as $attribute) {
                if (! $attribute->isFile()) {
                    continue;
                }

                $size = $attribute->fileSize()
                    ?? (int) $disk->size($attribute->path());

                $this->line(basename($attribute->path())."\t".(string) $size);
            }
        } catch (Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
