<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyYandexPartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('yandex_disk');
        Storage::disk('yandex_disk')->put('Laravel/2026-08-22-17-09-58.part-01-of-03.zip', str_repeat('A', 1234));
    }

    public function test_existing_part_with_matching_size_passes(): void
    {
        $this->artisan('backup:verify-yandex-part', [
            'path' => 'Laravel/2026-08-22-17-09-58.part-01-of-03.zip',
            '--size' => '1234',
        ])->assertExitCode(0);
    }

    public function test_missing_part_fails(): void
    {
        $this->artisan('backup:verify-yandex-part', [
            'path' => 'Laravel/2099-01-01-00-00-00.part-01-of-01.zip',
            '--size' => '1234',
        ])->assertExitCode(1);
    }

    public function test_size_mismatch_fails(): void
    {
        $this->artisan('backup:verify-yandex-part', [
            'path' => 'Laravel/2026-08-22-17-09-58.part-01-of-03.zip',
            '--size' => '999',
        ])->assertExitCode(1);
    }
}
