<?php

declare(strict_types=1);

namespace App\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Измеряет вес каталогов загрузок и свободное место на диске (H1345).
 *
 * Отдельный сервис, а не логика внутри команды, по образцу
 * {@see ReceivablesGovernanceService}: тот же снимок нужен и дежурной команде
 * `storage:check`, и — в будущем — админ-странице, и тестам, которые не должны
 * гонять artisan ради арифметики.
 *
 * Все пороги читаются из config/storage_watch.php, ни одного числа здесь.
 */
class StorageUsageService
{
    /**
     * Снимок состояния хранилища.
     *
     * @return array{
     *     ok: bool,
     *     alerts: list<string>,
     *     directories: list<array{path: string, bytes: int, limit_mb: int, ratio: float, level: string, truncated: bool}>,
     *     total_bytes: int,
     *     total_limit_mb: int,
     *     total_level: string,
     *     free_disk_bytes: int|null,
     *     free_disk_level: string
     * }
     */
    public function snapshot(): array
    {
        $warnRatio = (float) config('storage_watch.warn_ratio', 0.8);
        $fileCap = (int) config('storage_watch.scan_file_cap', 20000);

        $directories = [];
        $alerts = [];

        foreach ((array) config('storage_watch.watched', []) as $relative => $limitMb) {
            $limitMb = (int) $limitMb;
            [$bytes, $truncated] = $this->measure(storage_path('app/'.$relative), $fileCap);

            $ratio = $limitMb > 0 ? $bytes / ($limitMb * 1048576) : 0.0;
            $level = $this->level($ratio, $warnRatio);

            $directories[] = [
                'path' => $relative,
                'bytes' => $bytes,
                'limit_mb' => $limitMb,
                'ratio' => $ratio,
                'level' => $level,
                'truncated' => $truncated,
            ];

            if ($level !== 'green') {
                $alerts[] = sprintf(
                    '%s: %s из %s МБ (%d%%).',
                    $relative,
                    $this->megabytes($bytes),
                    number_format($limitMb, 0, '.', ' '),
                    (int) round($ratio * 100)
                );
            }

            // Усечённый обход — это НЕ «всё хорошо»: настоящий вес больше
            // измеренного, поэтому говорим об этом отдельной строкой, даже
            // если сам каталог пока в зелёной зоне.
            if ($truncated) {
                $alerts[] = sprintf(
                    '%s: обход остановлен на %s файлах — реальный вес БОЛЬШЕ измеренного.',
                    $relative,
                    number_format($fileCap, 0, '.', ' ')
                );
            }
        }

        [$totalBytes, $totalTruncated] = $this->measure(storage_path('app'), $fileCap);
        $totalLimitMb = (int) config('storage_watch.total_mb', 8000);
        $totalRatio = $totalLimitMb > 0 ? $totalBytes / ($totalLimitMb * 1048576) : 0.0;
        $totalLevel = $this->level($totalRatio, $warnRatio);

        if ($totalLevel !== 'green') {
            $alerts[] = sprintf(
                'Всего storage/app: %s из %s МБ (%d%%).',
                $this->megabytes($totalBytes),
                number_format($totalLimitMb, 0, '.', ' '),
                (int) round($totalRatio * 100)
            );
        }

        if ($totalTruncated) {
            $alerts[] = 'Обход storage/app усечён предохранителем — итог занижен.';
        }

        [$freeBytes, $freeLevel, $freeAlert] = $this->freeDisk();

        if ($freeAlert !== null) {
            $alerts[] = $freeAlert;
        }

        return [
            'ok' => $alerts === [],
            'alerts' => $alerts,
            'directories' => $directories,
            'total_bytes' => $totalBytes,
            'total_limit_mb' => $totalLimitMb,
            'total_level' => $totalLevel,
            'free_disk_bytes' => $freeBytes,
            'free_disk_level' => $freeLevel,
        ];
    }

    /**
     * Рекурсивный вес каталога с предохранителем по количеству файлов.
     *
     * @return array{0: int, 1: bool} [байты, был ли обход усечён]
     */
    private function measure(string $absolutePath, int $fileCap): array
    {
        if (! is_dir($absolutePath)) {
            return [0, false]; // каталог ещё не создан — это ноль, а не ошибка
        }

        if (! is_readable($absolutePath)) {
            return [0, true];
        }

        $bytes = 0;
        $seen = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $bytes += (int) $file->getSize();

                if (++$seen >= $fileCap && $fileCap > 0) {
                    return [$bytes, true];
                }
            }
        } catch (\UnexpectedValueException) {
            return [$bytes, true];
        }

        return [$bytes, false];
    }

    /**
     * @return array{0: int|null, 1: string, 2: string|null} [байты, уровень, текст алерта]
     */
    private function freeDisk(): array
    {
        $minFreeMb = (int) config('storage_watch.min_free_disk_mb', 2048);

        if ($minFreeMb <= 0) {
            return [null, 'off', null]; // проверка намеренно выключена
        }

        $free = @disk_free_space(storage_path('app'));

        // В контейнере или при закрытом open_basedir функция возвращает false.
        // Молча считать это «места полно» нельзя — но и алертить каждый день
        // о том, что метрика недоступна, бессмысленно: помечаем как unknown.
        if ($free === false) {
            return [null, 'unknown', null];
        }

        $free = (int) $free;

        if ($free < $minFreeMb * 1048576) {
            return [$free, 'red', sprintf(
                'Свободного места на диске %s, минимум %s МБ — это уже инцидент, а не рост.',
                $this->megabytes($free),
                number_format($minFreeMb, 0, '.', ' ')
            )];
        }

        return [$free, 'green', null];
    }

    private function level(float $ratio, float $warnRatio): string
    {
        return match (true) {
            $ratio >= 1.0 => 'red',
            $ratio >= $warnRatio => 'yellow',
            default => 'green',
        };
    }

    public function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, '.', ' ').' МБ';
    }
}
