<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Скачивает и атомарно кладёт GeoLite2-City.mmdb для драйвера 'maxmind'
 * (H3445, решение MG 24-08-2026). Нужны MAXMIND_ACCOUNT_ID + MAXMIND_LICENSE_KEY
 * (бесплатная регистрация на maxmind.com). База — gitignored-файл в storage.
 *
 *   php artisan support:geo-update-maxmind [--dry-run]
 *
 * Расписание: еженедельно вс 04:40 (Kernel), только когда driver=maxmind.
 */
class UpdateMaxMindGeoDatabase extends Command
{
    protected $signature = 'support:geo-update-maxmind {--dry-run : Скачать и проверить, но не заменять рабочий файл}';

    protected $description = 'Download and install the MaxMind GeoLite2-City .mmdb database for SUPPORT_GEO_DRIVER=maxmind';

    private const DOWNLOAD_URL = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

    public function handle(): int
    {
        $accountId = (string) config('support_geo.maxmind_account_id', '');
        $licenseKey = (string) config('support_geo.maxmind_license_key', '');
        $targetPath = (string) config('support_geo.maxmind_path', '');

        if ($accountId === '' || $licenseKey === '') {
            $this->error('MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY не заданы (.env).');

            return self::FAILURE;
        }

        if ($targetPath === '') {
            $this->error('SUPPORT_GEO_MAXMIND_PATH пуст — некуда класть базу.');

            return self::FAILURE;
        }

        $tmpDir = storage_path('app/geo');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $archivePath = $tmpDir.'/GeoLite2-City.'.uniqid().'.tar.gz';

        $this->info('Downloading GeoLite2-City…');

        $response = Http::withBasicAuth($accountId, $licenseKey)
            ->timeout(300)
            ->sink($archivePath)
            ->get(self::DOWNLOAD_URL);

        if (! $response->ok()) {
            $this->error('Download failed: HTTP '.$response->status());

            @unlink($archivePath);

            return self::FAILURE;
        }

        // Страховка: некоторые клиенты/обёртки не наполняют sink — тогда пишем
        // тело ответа вручную (не меняет поведение при настоящей закачке).
        if (! is_file($archivePath) || (filesize($archivePath) ?: 0) === 0) {
            file_put_contents($archivePath, $response->body());
        }

        $mmdb = $this->extractMmdb($archivePath, $tmpDir);

        @unlink($archivePath);

        if ($mmdb === null) {
            $this->error('Архив не содержит валидного GeoLite2-City.mmdb.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run OK: база читается, '.number_format((float) (filesize($mmdb) ?: 0) / 1048576, 1).' MiB — рабочий файл НЕ заменён.');
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
        $this->info('Установлена GeoLite2-City: '.$targetPath.' ('.number_format((float) (filesize($targetPath) ?: 0) / 1048576, 1).' MiB).');

        return self::SUCCESS;
    }

    /**
     * Разворачивает tar.gz и возвращает путь к СКОПИРОВАННОМУ .mmdb во временном
     * каталоге (не внутри архива), либо null. Валидность проверяется размером:
     * настоящую .mmdb-структуру проверит Reader при первом резолве.
     */
    private function extractMmdb(string $archivePath, string $workDir): ?string
    {
        $tarPath = dirname($archivePath).'/'.basename($archivePath, '.gz');
        copy($archivePath, $tarPath);

        try {
            $phar = new \PharData($tarPath);

            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getFilename() !== 'GeoLite2-City.mmdb') {
                    continue;
                }

                $content = @file_get_contents($file->getPathname());

                if ($content === false || strlen($content) < 1024) {
                    continue;
                }

                $staged = $workDir.'/'.uniqid('geolite-staged-').'.mmdb';

                return file_put_contents($staged, $content) !== false ? $staged : null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->error('Extract failed: '.$e->getMessage());

            return null;
        } finally {
            @unlink($tarPath);
        }
    }
}
