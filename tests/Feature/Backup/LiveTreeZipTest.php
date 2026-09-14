<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\LiveTreeZip;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Spatie\Backup\Config\BackupConfig;
use Tests\TestCase;
use ZipArchive;

class LiveTreeZipTest extends TestCase
{
    /** @test */
    public function unreadable_members_are_skipped_with_warning_not_fatal(): void
    {
        // Прод 22-08-2026: 2519 root-owned файлов в storage/app роняли close()
        // («Can't open file: Permission denied») — молчаливый фолбэк freezeFile
        // подставлял нечитаемый оригинал, libzip умирал уже после минут копирования.
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX chmod required to model an unreadable member');
        }

        // FINDINGS §513: у libzip части раннеров close() падает «Invalid
        // argument» на компрессионных параметрах — тестируем пропуск члена,
        // а не компрессию, поэтому STORE без уровня.
        config([
            'backup.backup.destination.compression_method' => ZipArchive::CM_STORE,
            'backup.backup.destination.compression_level' => 0,
            'backup.backup.password' => null,
        ]);
        // BackupConfig — контейнерный синглтон: подменяем свежим с нашими
        // override-ами, иначе конструктор возьмёт конфиг из чужого теста.
        $this->app->instance(
            BackupConfig::class,
            BackupConfig::fromArray(config('backup.backup'))
        );

        $dir = sys_get_temp_dir().'/livetree-'.uniqid();
        mkdir($dir.'/sub', 0777, true);

        $readable = $dir.'/ok.txt';
        file_put_contents($readable, 'hello');
        $unreadable = $dir.'/sub/secret.txt';
        file_put_contents($unreadable, 'nope');
        chmod($unreadable, 0000);

        Log::spy();

        try {
            $zipPath = $dir.'/out.zip';
            $zip = new LiveTreeZip($zipPath);
            $zip->add([$readable, $unreadable]);

            // Контракт пропуска виден уже до close(): нечитаемый член не попал.
            $za = (new ReflectionClass($zip))->getProperty('zipFile');
            $za->setAccessible(true);
            $archive = $za->getValue($zip);
            $this->assertSame(1, $archive->numFiles);
            $this->assertNotFalse($archive->locateName(ltrim($readable, '/')));

            $zip->close();

            $check = new ZipArchive;
            $this->assertTrue((bool) $check->open($zipPath));
            $this->assertSame(1, $check->numFiles);
            $this->assertNotFalse($check->locateName(ltrim($readable, '/')));
            $check->close();

            Log::shouldHaveReceived('warning')->once()->withArgs(
                fn (string $message, array $context) => $context['count'] === 1
                    && $context['files'] === [$unreadable]
            );
        } finally {
            chmod($unreadable, 0644);
            foreach ([$readable, $unreadable] as $f) {
                @unlink($f);
            }
            @rmdir($dir.'/sub');
            @unlink($dir.'/out.zip');
            @rmdir($dir);
        }
    }
}
