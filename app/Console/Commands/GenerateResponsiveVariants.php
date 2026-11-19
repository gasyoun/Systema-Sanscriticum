<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\ResponsiveDerivatives;
use Illuminate\Console\Command;

/**
 * Разовый backfill и ежедневная уборка responsive-вариантов (srcset).
 *
 * Наблюдателя на загрузку у медиа Куратора нет — поэтому команда ходит по
 * каталогам и добирает всё, что появилось мимо неё. В расписании стоит после
 * `media:covers-to-webp`, чтобы оба прохода не спорили за одни и те же файлы.
 *
 * Идемпотентна: свежий вариант (не старше исходника) не пересобирается.
 */
class GenerateResponsiveVariants extends Command
{
    protected $signature = 'media:responsive-variants
        {--dry-run : только посчитать кандидатов, ничего не писать}
        {--limit=0 : обработать не больше N исходников за прогон (0 — все)}
        {--force : пересобрать варианты даже там, где они свежее исходника}
        {--dir=* : каталоги вместо media.responsive.dirs}';

    protected $description = 'Сгенерировать WebP-варианты ширины (srcset) для картинок витрины и медиа';

    public function handle(ResponsiveDerivatives $derivatives): int
    {
        if (! $derivatives->enabled()) {
            $this->error('GD без поддержки WebP или media.responsive.enabled=false — генерировать нечем.');

            return self::FAILURE;
        }

        $fs = $derivatives->disk();
        $dirs = (array) ($this->option('dir') ?: config('media.responsive.dirs', []));
        $minBytes = max(0, (int) config('media.responsive.min_bytes', 80000));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $candidates = 0;
        $processed = 0;
        $variants = 0;
        $sourceBytes = 0;
        $variantBytes = 0;
        $reasons = [];

        foreach ($dirs as $dir) {
            $dir = trim((string) $dir, '/');

            if ($dir === '' || ! $fs->directoryExists($dir)) {
                $this->warn("{$dir}: каталога нет, пропущено.");

                continue;
            }

            $files = $fs->allFiles($dir);
            $this->line(sprintf('%s: файлов %d', $dir, count($files)));

            foreach ($files as $path) {
                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    continue;
                }

                // Свой же вывод не считаем исходником: иначе второй прогон
                // сделает `one-w400-w400.webp` и «уборка» начнёт размножаться.
                if (preg_match('/-w\d+\.webp$/i', $path) === 1) {
                    continue;
                }

                if ($fs->size($path) < $minBytes) {
                    continue;
                }

                $candidates++;

                if ($dryRun) {
                    if ($candidates <= 20) {
                        $this->line('  [dry-run] '.$path);
                    }

                    continue;
                }

                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }

                $processed++;
                $result = $derivatives->generate($path, null, $force);

                if ($result['created'] !== []) {
                    $variants += count($result['created']);
                    $sourceBytes += $fs->size($path);

                    foreach ($result['created'] as $created) {
                        $variantBytes += $created['bytes'];
                    }

                    $this->line(sprintf(
                        '  ✓ %s → %s',
                        $path,
                        implode(', ', array_map(
                            fn (array $c): string => $c['width'].'w ('.$this->human($c['bytes']).')',
                            $result['created'],
                        )),
                    ));
                }

                foreach ($result['skipped'] as $reason) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(sprintf('Dry-run: кандидатов %d (порог %s), ничего не записано.', $candidates, $this->human($minBytes)));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Готово: исходников %d, вариантов %d, %s → %s.',
            $processed,
            $variants,
            $this->human($sourceBytes),
            $this->human($variantBytes),
        ));

        // Молчаливый пропуск — это ровно тот случай, когда «уборка отработала»
        // и ничего при этом не сделала. Печатаем причины всегда.
        foreach ($reasons as $reason => $count) {
            $this->line("  {$reason}: {$count}");
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f МБ', $bytes / 1048576);
        }

        return sprintf('%d КБ', (int) round($bytes / 1024));
    }
}
