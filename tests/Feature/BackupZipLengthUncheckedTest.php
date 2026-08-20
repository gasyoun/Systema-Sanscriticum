<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Backup\BackupRunCommand;
use App\Support\Backup\LiveTreeZip;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use ZipArchive;

/**
 * FINDINGS §513 / H3195: backup zip must survive a member shrinking after addFile.
 */
class BackupZipLengthUncheckedTest extends TestCase
{
    /** @test */
    public function backup_run_resolves_to_the_live_tree_command(): void
    {
        $command = Artisan::all()['backup:run'] ?? null;

        $this->assertInstanceOf(BackupRunCommand::class, $command);
    }

    /** @test */
    public function live_tree_zip_close_survives_a_shrunk_member_and_keeps_the_size_at_add(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h3195-zip-'.uniqid();
        mkdir($dir);
        $member = $dir.DIRECTORY_SEPARATOR.'member.bin';
        $zipPath = $dir.DIRECTORY_SEPARATOR.'out.zip';
        file_put_contents($member, str_repeat('a', 20_000));

        try {
            $zip = new LiveTreeZip($zipPath);
            $zip->add($member, 'member.bin');
            file_put_contents($member, str_repeat('a', 1_000));
            $zip->close();

            $this->assertFileExists($zipPath);
            $this->assertGreaterThan(0, filesize($zipPath));

            $reader = new ZipArchive;
            $this->assertTrue($reader->open($zipPath, ZipArchive::RDONLY));
            $this->assertSame(1, $reader->numFiles);
            $stat = $reader->statIndex(0);
            $this->assertIsArray($stat);
            $this->assertSame(20_000, $stat['size']);
            $reader->close();
        } finally {
            @unlink($zipPath);
            @unlink($member);
            @rmdir($dir.DIRECTORY_SEPARATOR.'live-tree-snap');
            @rmdir($dir);
        }
    }
}
