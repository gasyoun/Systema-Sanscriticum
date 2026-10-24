<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Listeners\Backup\SplitUploadToYandex;
use App\Support\Backup\LiveTreeZip;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupWasSuccessful;
use Tests\TestCase;
use ZipArchive;

/**
 * H6306 (аудит MATURITY_BACKUPS_DR_2026-10-09, канал 3): незашифрованный
 * spatie-архив не должен готовиться к off-site выгрузке, когда пароль
 * отсутствует или пуст. Поведенческий пример был красным до фикса:
 * handle() при null-пароле молча выгружал части открытым zip'ом.
 *
 * Синтетика: фикстуры — случайные байты на Storage::fake, пароль тестовый;
 * ни один реальный архив/секрет не читается.
 */
class OffsiteArchiveEncryptionGateTest extends TestCase
{
    private const NAME = 'TestApp';

    private const PASSWORD = 'synthetic-h6306-archive-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.backup.name' => self::NAME,
            'backup.backup.split_upload.disk' => 'yandex_disk',
            'backup.backup.split_upload.max_part_mb' => 1,
            'backup.backup.split_upload.keep_parts_days' => 16,
            'backup.backup.split_upload.verify' => false,
            // По умолчанию в тестах пароля НЕТ — каждый тест показывает
            // своё поведение явно (отказ или прохождение).
            'backup.backup.password' => null,
        ]);
        Storage::fake('local');
        Storage::fake('yandex_disk');
    }

    public function test_offsite_upload_refuses_without_password_before_any_part_leaves(): void
    {
        $path = self::NAME.'/2026-10-09-07-41-00.zip';
        Storage::disk('local')->put($path, str_repeat('A', 2 * 1024 * 1024 + 500));

        Log::shouldReceive('error')->atLeast()->once()->andReturnUsing(
            fn (string $message) => $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $message)
        );

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertCount(
            0,
            Storage::disk('yandex_disk')->allFiles(self::NAME),
            'Без пароля ни одна часть не должна уезжать off-site (H6306).'
        );
        $this->assertTrue(
            Storage::disk('local')->exists($path),
            'Local-копия обязана остаться нетронутой: гейт запрещает только off-site ногу.'
        );
    }

    public function test_offsite_upload_refuses_with_empty_password(): void
    {
        // `.env.example` хранит `BACKUP_ARCHIVE_PASSWORD=` (пустая строка, не
        // null): она обязана считаться отсутствием — spatie с пустым паролем
        // «шифрует» пустым паролем (FINDINGS §513), части = открытый zip.
        config(['backup.backup.password' => '']);
        $this->app->forgetInstance(Config::class);

        Storage::disk('local')->put(self::NAME.'/2026-10-09-07-41-00.zip', 'A');

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertCount(0, Storage::disk('yandex_disk')->allFiles(self::NAME));
    }

    public function test_resume_refuses_without_password(): void
    {
        // Незавершённая группа прошлых прогонов + живой локальный ствол —
        // докатка обязана отказать тем же гейтом, что и основная выгрузка.
        Storage::disk('local')->put(self::NAME.'/2026-10-09-07-41-00.zip', str_repeat('A', 100));
        Storage::disk('yandex_disk')->put(self::NAME.'/2026-10-09-07-41-00.part-02-of-03.zip', 'B');

        (new SplitUploadToYandex)->resumeOffsite();

        $this->assertSame(
            ['TestApp/2026-10-09-07-41-00.part-02-of-03.zip'],
            Storage::disk('yandex_disk')->allFiles(self::NAME),
            'Докатка без пароля не должна доливать НИ ОДНОЙ части.'
        );
    }

    public function test_offsite_upload_proceeds_when_password_configured(): void
    {
        config(['backup.backup.password' => self::PASSWORD]);
        $this->app->forgetInstance(Config::class);

        $original = random_bytes(2 * 1024 * 1024 + 123);
        Storage::disk('local')->put(self::NAME.'/2026-10-09-07-41-00.zip', $original);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $parts = Storage::disk('yandex_disk')->allFiles(self::NAME);
        $this->assertCount(3, $parts, 'Гейт не должен ломать штатную механику разбиения под заданным паролем.');
    }

    public function test_deploy_config_preflight_fails_in_production_without_password(): void
    {
        config([
            'app.env' => 'production',
            'session.secure' => true,
            'security.trusted_proxies' => '10.0.0.0/8',
        ]);

        $exit = Artisan::call('deploy:config-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'Прод без BACKUP_ARCHIVE_PASSWORD при включённой off-site ноге — hard-fail деплоя.');
        $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function test_deploy_config_preflight_passes_in_production_with_password(): void
    {
        config([
            'app.env' => 'production',
            'session.secure' => true,
            'security.trusted_proxies' => '10.0.0.0/8',
            'backup.backup.password' => self::PASSWORD,
        ]);

        $exit = Artisan::call('deploy:config-preflight');

        $this->assertSame(0, $exit);
    }

    public function test_deploy_config_preflight_ignores_disabled_offsite_leg(): void
    {
        // Нога выключена (диск local) — гейт молчит: локальный бэкап без
        // пароля не является off-site-утечкой, ругаться незачем.
        config([
            'app.env' => 'production',
            'session.secure' => true,
            'security.trusted_proxies' => '10.0.0.0/8',
            'backup.backup.split_upload.disk' => 'local',
        ]);

        $exit = Artisan::call('deploy:config-preflight');

        $this->assertSame(0, $exit);
    }

    /**
     * Locked acceptance: синтетический архив нечитаем без пароля и
     * байт-в-байт восстанавливается с паролем (round-trip, hash match).
     * Используется тот же LiveTreeZip, что и в боевом backup:run —
     * шифрование идёт через spatie Config (EM_AES_256 для 'default').
     */
    public function test_encrypted_fixture_roundtrips_and_is_unreadable_without_password(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h6306-rt-'.uniqid();
        mkdir($dir);
        $member = $dir.DIRECTORY_SEPARATOR.'member.bin';
        $zipPath = $dir.DIRECTORY_SEPARATOR.'encrypted.zip';
        $payload = random_bytes(64 * 1024);
        file_put_contents($member, $payload);
        $payloadHash = hash('sha256', $payload);

        config([
            'backup.backup.password' => self::PASSWORD,
            'backup.backup.encryption' => 'default',
        ]);
        $this->app->forgetInstance(Config::class);

        try {
            $zip = new LiveTreeZip($zipPath);
            $zip->add($member, 'member.bin');
            $zip->close();

            $this->assertFileExists($zipPath);

            // 1) Член действительно зашифрован.
            $probe = new ZipArchive;
            $this->assertTrue($probe->open($zipPath, ZipArchive::RDONLY));
            $stat = $probe->statIndex(0);
            $this->assertIsArray($stat);
            $this->assertNotSame(0, $stat['encryption_method'], 'Член zip обязан нести ненулевой encryption_method.');

            // 2) Без пароля содержимое недоступно.
            $this->assertFalse(
                @$probe->getFromName('member.bin'),
                'Чтение члена без пароля обязано провалиться.'
            );
            $probe->close();

            // 3) С паролем — байт-в-байт round-trip.
            $reader = new ZipArchive;
            $this->assertTrue($reader->open($zipPath, ZipArchive::RDONLY));
            $this->assertTrue($reader->setPassword(self::PASSWORD));
            $restored = $reader->getFromName('member.bin');
            $this->assertIsString($restored);
            $this->assertSame($payloadHash, hash('sha256', $restored), 'Round-trip обязан дать исходный hash.');
            $reader->close();
        } finally {
            @unlink($zipPath);
            @unlink($member);
            @rmdir($dir.DIRECTORY_SEPARATOR.'live-tree-snap');
            @rmdir($dir);
        }
    }
}
