<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Backup\BackupRunCommand;
use App\Support\Backup\LengthUncheckedZip;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use ZipArchive;

/**
 * FINDINGS §513 / H3195: backup zip must survive a member shrinking after addFile.
 */
class BackupZipLengthUncheckedTest extends TestCase
{
    /** @test */
    public function backup_run_resolves_to_the_length_unchecked_command(): void
    {
        $command = Artisan::all()['backup:run'] ?? null;

        $this->assertInstanceOf(BackupRunCommand::class, $command);
    }

    /** @test */
    public function length_unchecked_zip_close_survives_a_shrunk_member(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h3195-zip-'.uniqid();
        mkdir($dir);
        $member = $dir.DIRECTORY_SEPARATOR.'member.bin';
        $zipPath = $dir.DIRECTORY_SEPARATOR.'out.zip';
        file_put_contents($member, str_repeat('a', 20_000));

        try {
            $zip = new LengthUncheckedZip($zipPath);
            $zip->add($member, 'member.bin');
            file_put_contents($member, str_repeat('a', 1_000));
            $zip->close();

            $this->assertFileExists($zipPath);
            $this->assertGreaterThan(0, filesize($zipPath));

            $reader = new ZipArchive;
            $this->assertTrue($reader->open($zipPath, ZipArchive::RDONLY));
            $this->assertSame(1, $reader->numFiles);
            $reader->close();
        } finally {
            @unlink($zipPath);
            @unlink($member);
            @rmdir($dir);
        }
    }

    /** @test */
    public function default_ziparchive_close_rejects_a_shrunk_member(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h3195-zip-default-'.uniqid();
        mkdir($dir);
        $member = $dir.DIRECTORY_SEPARATOR.'member.bin';
        $zipPath = $dir.DIRECTORY_SEPARATOR.'out.zip';
        file_put_contents($member, str_repeat('a', 20_000));

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFile($member, 'member.bin');
        file_put_contents($member, str_repeat('a', 1_000));

        $message = null;
        $closed = null;
        try {
            $closed = $archive->close();
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            @unlink($zipPath);
            @unlink($member);
            @rmdir($dir);
        }

        if ($message === null) {
            $this->assertFalse($closed);
        } else {
            $this->assertStringContainsString('Unexpected length of data', $message);
        }
    }
}
