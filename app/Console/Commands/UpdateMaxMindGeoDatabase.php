<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Скачивает и атомарно кладёт справочник IP→город для драйвера 'maxmind'
 * (H3445). Источник — DB-IP City Lite (H6602, решение MG 13-10-2026 по гриллу
 * H6577): тот же формат MaxMind DB (читает maxmind-db/reader), файл выходит
 * ежемесячно, скачивание БЕЗ регистрации и ключей, лицензия CC BY 4.0
 * (атрибуция — в политике конфиденциальности). База — gitignored-файл в storage.
 *
 *   php artisan support:geo-update-maxmind [--dry-run]
 *
 * Файл месяца называется dbip-city-lite-YYYY-MM.mmdb.gz; в начале месяца
 * свежий ещё не опубликован — тогда берём предыдущий месяц. Имя команды
 * сохранено (расписание и ручные прогоны не переучиваем).
 */
class UpdateMaxMindGeoDatabase extends Command
{
    protected $signature = 'support:geo-update-maxmind {--dry-run : Скачать и проверить, но не заменять рабочий файл}';

    protected $description = 'Download and install the DB-IP City Lite .mmdb database (MaxMind DB format) for SUPPORT_GEO_DRIVER=maxmind';

    private const DOWNLOAD_BASE_URL = 'https://download.db-ip.com/free/dbip-city-lite-';

    public function handle(): int
    {
        $targetPath = (string) config('support_geo.maxmind_path', '');

        if ($targetPath === '') {
            $this->error('SUPPORT_GEO_MAXMIND_PATH пуст — некуда класть базу.');

            return self::FAILURE;
        }

        $tmpDir = storage_path('app/geo');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Текущий месяц → предыдущий (в первые дни месяца свежего ещё нет).
        $months = [now()->format('Y-m'), now()->subMonth()->format('Y-m')];

        $mmdb = null;

        foreach ($months as $month) {
            $url = self::DOWNLOAD_BASE_URL.$month.'.mmdb.gz';
            $this->info("Downloading DB-IP City Lite {$month}…");

            $archivePath = $tmpDir.'/dbip-city-lite-'.uniqid().'.mmdb.gz';

            try {
                $response = Http::timeout(300)->sink($archivePath)->get($url);
            } catch (\Throwable $e) {
                $this->warn("Download failed: {$e->getMessage()}");
                @unlink($archivePath);

                continue;
            }

            if (! $response->ok()) {
                $this->warn("Download failed: HTTP {$response->status()}");
                @unlink($archivePath);

                continue;
            }

            // Страховка: некоторые клиенты/обертки не наполняют sink — тогда
            // пишем тело ответа вручную (не меняет поведение при настоящей закачке).
            if (! is_file($archivePath) || (filesize($archivePath) ?: 0) === 0) {
                file_put_contents($archivePath, $response->body());
            }

            $mmdb = $this->extractMmdb($archivePath, $tmpDir);
            @unlink($archivePath);

            if ($mmdb !== null) {
                break;
            }

            $this->warn('Архив не содержит валидного .mmdb — пробуем предыдущий месяц.');
        }

        if ($mmdb === null) {
            $this->error('Ни один источник DB-IP City Lite не дал валидной базы.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run OK: база читается, '.number_format((float) (filesize($mmdb) ?: 0) / 1048576, 1).' MiB — рабочий файл НЕ заменен.');
            @unlink($mmdb);

            return self::SUCCESS;
        }

        // Атомарная замена на том же томе: уже открытые Reader продолжают видеть
        // старый инод, новые открывают новый файл. Перезапуск воркеров не нужен.
        $staged = $targetPath.'.staged';
        rename($mmdb, $staged);

        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        rename($staged, $targetPath);
        chmod($targetPath, 0640);

        clearstatcache();
        $this->info('Установлена DB-IP City Lite (формат MaxMind DB): '.$targetPath.' ('.number_format((float) (filesize($targetPath) ?: 0) / 1048576, 1).' MiB).');

        return self::SUCCESS;
    }

    /**
     * Распаковывает .mmdb.gz и возвращает путь к РАСПАКОВАННОЙ базе во временном
     * каталоге, либо null. Валидность проверяется структурно: настоящий
     * MaxMind-формат заканчивается метаданными за маркером \xAB\xCD\xEFMaxMind.com
     * (DB-IP пишет им же); размер отсекает обрезанные/пустые ответы. Полную
     * валидность проверит Reader при первом резолве.
     */
    private function extractMmdb(string $archivePath, string $workDir): ?string
    {
        $gz = @gzopen($archivePath, 'rb');

        if ($gz === false) {
            $this->error('Extract failed: не gzip.');

            return null;
        }

        $staged = $workDir.'/'.uniqid('dbip-staged-').'.mmdb';
        $out = @fopen($staged, 'wb');

        if ($out === false) {
            gzclose($gz);
            $this->error('Extract failed: не открыть staging-файл.');

            return null;
        }

        while (! gzeof($gz)) {
            $chunk = gzread($gz, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
        }

        fclose($out);
        gzclose($gz);

        $size = filesize($staged) ?: 0;

        if ($size < 1024 || ! $this->hasMaxMindMetadataMarker($staged)) {
            @unlink($staged);

            return null;
        }

        return $staged;
    }

    /** Маркер метаданных MaxMind DB (\xAB\xCD\xEFMaxMind.com) — в конце файла. */
    private function hasMaxMindMetadataMarker(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $window = 131072;
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle) ?: 0;
        fseek($handle, max(0, $size - $window), SEEK_SET);
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return str_contains($tail, "\xAB\xCD\xEFMaxMind.com");
    }
}
