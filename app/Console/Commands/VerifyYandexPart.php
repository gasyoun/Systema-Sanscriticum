<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Свежепроцессная проверка части split-upload: сабра держит один персистентный
 * curl-хендл на процесс, и после PUT с телом обратные запросы из того же
 * процесса ненадёжны («necessary data rewind», прод 22-08-2026). Плюс Яндекс
 * изредка отвечал 2xx вообще ничего не сохранив. Поэтому факт существования и
 * размер части меряет ОТДЕЛЬНЫЙ короткий процесс: свежий хендл — честный
 * PROPFIND. SplitUploadToYandex зовёт это после каждого PUT и ретраит часть
 * при расхождении.
 */
class VerifyYandexPart extends Command
{
    protected $signature = 'backup:verify-yandex-part
                            {path : Path of the part inside the disk}
                            {--size= : Expected size in bytes (0 = skip size check)}
                            {--disk=yandex_disk : Off-site disk name}';

    protected $description = 'Fresh-process check that a split-upload part really landed on the off-site disk';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $expected = (int) $this->option('size');

        try {
            $disk = Storage::disk((string) $this->option('disk'));

            if (! $disk->exists($path)) {
                $this->error("missing: {$path}");

                return self::FAILURE;
            }

            $actual = (int) $disk->size($path);
            if ($expected > 0 && $actual !== $expected) {
                $this->error("size mismatch for {$path}: got {$actual}, expected {$expected}");

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("ok: {$path} = {$actual}");

        return self::SUCCESS;
    }
}
