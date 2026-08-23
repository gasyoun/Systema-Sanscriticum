<?php

declare(strict_types=1);

namespace App\Listeners\Backup;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupWasSuccessful;
use Throwable;

/**
 * Off-site нога бэкапа: Yandex Disk режет один WebDAV-PUT больше ~1 ГБ по
 * HTTP 413, поэтому полный архив туда напрямую не попадал НИКОГДА (на диске
 * жили только обрезки по 11 МиБ). Здесь архив с local делится на части,
 * влезающие в лимит, и льётся по частям на диск split_upload.disk.
 *
 * Имена частей: `<Y-m-d-H-i-s>.part-NN-of-NN.zip` — расширение .zip оставлено,
 * чтобы spatie BackupCollection (а значит guards/backup-fresh и backup:monitor)
 * считал части полноценными архивами: свежесть меряется по новейшей части.
 *
 * Части льются В ОБРАТНОМ порядке (от последней к первой): последняя часть
 * может быть короче лимита, а guards проверяют размер именно новейшего файла —
 * реверс гарантирует, что новейшей окажется гарантированно полная часть.
 *
 * КАЖДЫЙ PUT верифицируется свежим процессом (backup:verify-yandex-part):
 * сабра держит один curl-хендл на процесс и после PUT с телом врёт/падает на
 * обратных запросах, а Яндекс изредка отвечал 2xx ничего не сохранив (прод
 * 22–23-08-2026). Расхождение = неудача попытки → ретрай; после второй неудачи
 * линия громко помечается деградировавшей (ERROR + guards краснеют по stale).
 *
 * Ретеншн: группы старше keep_parts_days удаляются целиком (spatie cleanup
 * off-site диск не обслуживает — его нет в destination.disks). Под групповую
 * маску попадают и легаси-обрезки с полными именами — тоже чистятся.
 *
 * Восстановление: скачать все части одной группы, склеить по номерам
 * (`cat 2026-08-22-17-09-58.part-*-of-*.zip > 2026-08-22-17-09-58.zip`),
 * распаковать как обычный zip.
 *
 * Любой сбой здесь НЕ должен портить уже успешный local-бэкап: логируем и
 * выходим — за свежестью off-site следят guards независимо.
 */
class SplitUploadToYandex
{
    /** Секунды на один свежепроцессный PROPFIND части. */
    private const VERIFY_TIMEOUT_SECONDS = 120;

    /** @var bool Гейт верификации (тесты выключают — subprocess не видит fake-диски). */
    private bool $verifyEnabled = true;

    public function handle(BackupWasSuccessful $event): void
    {
        // Источник — успешная копия на local; другие диски игнорируем.
        if ($event->diskName !== 'local') {
            return;
        }

        $targetDiskName = (string) config('backup.backup.split_upload.disk');
        $maxPartMb = (int) config('backup.backup.split_upload.max_part_mb', 700);
        $keepDays = (int) config('backup.backup.split_upload.keep_parts_days', 16);
        $this->verifyEnabled = (bool) config('backup.backup.split_upload.verify', true);

        if ($targetDiskName === '' || $targetDiskName === 'local' || $maxPartMb < 1) {
            return;
        }

        try {
            $this->upload(
                (string) config('backup.backup.name', ''),
                Storage::disk('local'),
                Storage::disk($targetDiskName),
                $maxPartMb * 1024 * 1024,
                $keepDays,
                $targetDiskName,
            );
        } catch (Throwable $e) {
            Log::error('split-upload: выгрузка частей не удалась, local-копия в безопасности', [
                'disk' => $targetDiskName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function upload(
        string $name,
        Filesystem $sourceDisk,
        Filesystem $target,
        int $maxPartBytes,
        int $keepDays,
        string $targetDiskName,
    ): void {
        if ($name === '') {
            return;
        }

        $source = BackupDestination::create('local', $name);
        if (! $source->isReachable()) {
            Log::error('split-upload: локальный диск недоступен, части не льём');

            return;
        }

        $newest = $source->backups()->newest();
        if ($newest === null || ! $newest->exists()) {
            Log::warning('split-upload: на local нет ни одного архива — львать нечего');

            return;
        }

        $sourcePath = $newest->path();
        $dir = ltrim((string) pathinfo($sourcePath, PATHINFO_DIRNAME), '/');
        $dir = $dir === '.' ? '' : $dir;
        $size = (int) $newest->sizeInBytes();
        $stem = (string) preg_replace('/\.zip$/', '', basename($sourcePath));

        $totalParts = (int) max(1, (int) ceil($size / $maxPartBytes));
        $plan = $totalParts === 1
            ? [['path' => $this->join($dir, basename($sourcePath)), 'offset' => 0, 'length' => $size]]
            : $this->planParts($dir, $stem, $totalParts, $maxPartBytes, $size);

        // Идемпотентность повторного запуска: группа уже уехала целиком — выходим.
        if ($this->groupAlreadyUploaded($target, $plan)) {
            return;
        }

        // Реверс: последняя часть может быть короткой, а guards смотрят на
        // размер новейшего файла — пусть новейшей будет гарантированно полная.
        foreach (array_reverse($plan) as $part) {
            // Мёртвый ствол TCP рвётся low-speed таймаутом (AppServiceProvider);
            // одна попытка — одно соединение, вторая открывает свежее.
            foreach ([1, 2] as $attempt) {
                try {
                    $this->uploadPart($sourceDisk, $target, $sourcePath, $part);
                    break;
                } catch (Throwable $e) {
                    if ($attempt === 2) {
                        throw $e;
                    }
                    Log::warning("split-upload: часть {$part['path']} не доехала, ретраю", [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Чистка идёт ПОСЛЕ PUT'ов: отравленный хендл может уронить листинг —
        // это не провал выгрузки, недочищенное подберёт следующий запуск.
        try {
            $this->pruneOldGroups($target, $dir, $stem, $keepDays);
        } catch (Throwable $e) {
            Log::warning('split-upload: ретеншн-чистка не прошла, отложено на следующий запуск', [
                'exception' => $e->getMessage(),
            ]);
        }

        Log::info('split-upload: off-site обновлён', [
            'disk' => $targetDiskName,
            'archive' => basename($sourcePath),
            'parts' => count($plan),
            'bytes' => $size,
        ]);
    }

    /**
     * План частей: [ ['path' => ..., 'offset' => int, 'length' => int], ... ].
     *
     * @return list<array{path: string, offset: int, length: int}>
     */
    private function planParts(string $dir, string $stem, int $totalParts, int $maxPartBytes, int $size): array
    {
        $plan = [];
        $offset = 0;

        for ($i = 1; $i <= $totalParts; $i++) {
            $length = (int) min($maxPartBytes, $size - $offset);
            $plan[] = [
                'path' => sprintf('%s.part-%02d-of-%02d.zip', $this->join($dir, $stem), $i, $totalParts),
                'offset' => $offset,
                'length' => $length,
            ];
            $offset += $length;
        }

        return $plan;
    }

    /**
     * @param  list<array{path: string, offset: int, length: int}>  $plan
     */
    private function groupAlreadyUploaded(Filesystem $target, array $plan): bool
    {
        foreach ($plan as $part) {
            try {
                if (! $target->exists($part['path']) || (int) $target->size($part['path']) !== $part['length']) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{path: string, offset: int, length: int}  $part
     */
    private function uploadPart(Filesystem $sourceDisk, Filesystem $target, string $sourcePath, array $part): void
    {
        $in = $sourceDisk->readStream($sourcePath);
        if ($in === null) {
            throw new RuntimeException("split-upload: не удалось открыть поток {$sourcePath}");
        }

        try {
            fseek($in, $part['offset']);
            $chunk = fopen('php://temp', 'r+b');
            if ($chunk === false) {
                throw new RuntimeException('split-upload: php://temp недоступен');
            }

            try {
                if (stream_copy_to_stream($in, $chunk, $part['length']) !== $part['length']) {
                    throw new RuntimeException("split-upload: недочитан кусок для {$part['path']}");
                }
                rewind($chunk);

                $target->delete($part['path']);
                $target->writeStream($part['path'], $chunk);
            } finally {
                fclose($chunk);
            }

            // ВЕРИМ HTTP-статусу, но не верим на слово сохранению: у sabre один
            // персистентный curl-хендл на процесс — обратные запросы после PUT
            // с телом ненадёжны («necessary data rewind»), а Яндекс изредка
            // отвечал 2xx вообще ничего не сохранив (прод 22-08/23-08: три
            // «успеха» за 9–12 минут на 2 ГБ, части 404). Факт существования и
            // размер меряет ОТДЕЛЬНЫЙ короткий процесс.
            if ($this->verifyEnabled) {
                $this->verifyPartRemotely($part);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Свежепроцессная проверка части (backup:verify-yandex-part). Расхождение
     * или недоступность диска = неудача попытки: вызывающий цикл удалит часть
     * и ретрайнет следующим соединением; после последней попытки наверх уйдёт
     * исключение и прогон честно пометит линию деградировавшей.
     *
     * @param  array{path: string, offset: int, length: int}  $part
     */
    private function verifyPartRemotely(array $part): void
    {
        $artisan = defined('ARTISAN_BINARY') && is_file(ARTISAN_BINARY)
            ? ARTISAN_BINARY
            : base_path('artisan');

        $result = Process::timeout(self::VERIFY_TIMEOUT_SECONDS)->run([
            PHP_BINARY,
            $artisan,
            'backup:verify-yandex-part',
            $part['path'],
            '--size='.(string) $part['length'],
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'split-upload: верификация %s не прошла (%s): %s',
                $part['path'],
                $result->exitCode() ?? 'timeout',
                trim((string) $result->errorOutput()) !== '' ? trim((string) $result->errorOutput()) : trim((string) $result->output())
            ));
        }
    }

    /**
     * Группы старше keep_days удаляются целиком — включая легаси-обрезки
     * полноразмерных имён: их паттерн совпадает с групповой маской.
     */
    private function pruneOldGroups(Filesystem $target, string $dir, string $currentStem, int $keepDays): void
    {
        if ($keepDays <= 0) {
            return;
        }

        $cutoff = Carbon::now()->subDays($keepDays)->startOfDay();

        $groups = [];
        foreach ($target->files($dir) as $file) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.(?:part-\d+-of-\d+\.)?zip$/', basename($file), $m) !== 1) {
                continue;
            }
            $groups[$m[1]][] = $file;
        }

        foreach ($groups as $stem => $groupFiles) {
            if ($stem === $currentStem) {
                continue;
            }
            try {
                $date = Carbon::createFromFormat('Y-m-d-H-i-s', $stem);
            } catch (InvalidFormatException) {
                continue;
            }
            if ($date === null || $date->gte($cutoff)) {
                continue;
            }
            foreach ($groupFiles as $file) {
                try {
                    $target->delete($file);
                } catch (Throwable $e) {
                    Log::warning("split-upload: не удалось удалить устаревшую часть {$file}", [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function join(string $dir, string $filename): string
    {
        return $dir === '' ? $filename : $dir.'/'.$filename;
    }
}
