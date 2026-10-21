<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H6556: свежепроцессный листинг off-site каталога (basename<TAB>size на
 * строку). Команда — тот же «свежий curl-хендл» паттерн, что и
 * backup:verify-yandex-part: сабра в длинноживущем процессе флапает, один
 * короткий процесс листит честно. Кривые креды/мёртвая сеть в проде дают
 * exit 1 в пределах таймаута диска — здесь этот исход покрыт несуществующим
 * диском (падение внутри handle, без зависания).
 */
class ListYandexPartsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('yandex_disk');
        Storage::disk('yandex_disk')->put('Laravel/2026-08-22-17-09-58.part-01-of-03.zip', str_repeat('A', 1234));
        Storage::disk('yandex_disk')->put('Laravel/2026-08-22-17-09-58.part-02-of-03.zip', str_repeat('B', 345));
    }

    public function test_lists_basename_and_size_per_line(): void
    {
        $this->artisan('backup:list-yandex-parts', ['dir' => 'Laravel'])->assertExitCode(0);

        $output = Artisan::output();
        $this->assertStringContainsString("2026-08-22-17-09-58.part-01-of-03.zip\t1234", $output);
        $this->assertStringContainsString("2026-08-22-17-09-58.part-02-of-03.zip\t345", $output);
    }

    public function test_empty_directory_lists_nothing_and_passes(): void
    {
        $this->artisan('backup:list-yandex-parts', ['dir' => 'Laravel/absent-dir'])->assertExitCode(0);
        $this->assertSame('', trim(Artisan::output()));
    }

    public function test_unreachable_disk_fails_without_hanging(): void
    {
        $this->artisan('backup:list-yandex-parts', [
            'dir' => 'Laravel',
            '--disk' => 'no_such_disk',
        ])->assertExitCode(1);
    }
}
