<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * H3308 — разовый перенос контента уроков с публичного диска на приватный.
 *
 * До фикса стенограммы (transcripts/), материалы (lesson-materials/) и справочные
 * файлы ДЗ (homework-prompts/) писались на disk('public') и раздавались статикой
 * из /storage без всякой авторизации. Новые записи уже идут в 'local'; команда
 * переносит накопленное, чтобы старые ссылки продолжали открываться через
 * гейт-роуты student.lesson.*.
 *
 * По умолчанию DRY-RUN (только отчёт). Реальный перенос + удаление публичных
 * оригиналов — только с --apply; публичный файл удаляется после успешной
 * копии и сверки размера.
 */
class PrivatizeGatedAssets extends Command
{
    protected $signature = 'lessons:privatize-gated-assets
        {--apply : реально перенести и удалить публичные оригиналы (по умолчанию dry-run)}
        {--keep-public : скопировать, но НЕ удалять публичные оригиналы}';

    protected $description = 'Переносит transcripts/, lesson-materials/, homework-prompts/ с публичного диска на приватный (H3308)';

    private const PREFIXES = ['transcripts/', 'lesson-materials/', 'homework-prompts/'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        $copied = 0;
        $skippedExisting = 0;
        $removedPublic = 0;
        $bytes = 0;

        foreach (self::PREFIXES as $prefix) {
            $files = $public->files($prefix);

            $this->line("{$prefix}: ".count($files).' file(s) on public disk');

            foreach ($files as $path) {
                if ($local->exists($path)) {
                    $skippedExisting++;

                    continue;
                }

                $size = (int) $public->size($path);

                if ($apply) {
                    $stream = $public->readStream($path);
                    $local->writeStream($path, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if ((int) $local->size($path) !== $size) {
                        $local->delete($path);
                        $this->error("size mismatch after copy, rolled back local copy: {$path}");

                        continue;
                    }
                }

                $copied++;
                $bytes += $size;

                if ($apply && ! $this->option('keep-public')) {
                    $public->delete($path);
                    $removedPublic++;
                }
            }
        }

        $mode = $apply ? ($this->option('keep-public') ? 'APPLY (copy only)' : 'APPLY (copy+delete public)') : 'DRY-RUN';
        $this->info("[{$mode}] copied={$copied} skipped-existing={$skippedExisting} removed-public={$removedPublic} bytes={$bytes}");

        return self::SUCCESS;
    }
}
