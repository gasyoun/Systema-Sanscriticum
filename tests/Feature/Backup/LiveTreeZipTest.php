<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\LiveTreeZip;
use Illuminate\Support\Facades\Log;
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
            $zip->close();

            $check = new ZipArchive;
            $this->assertTrue((bool) $check->open($zipPath, ZipArchive::CHECKCONS));
            $this->assertSame(1, $check->numFiles, 'Нечитаемый член не должен попасть в архив.');
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
