<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Phar;
use PharData;
use Tests\TestCase;

/**
 * H3445 — support:geo-update-maxmind: скачивание, распаковка tar.gz и
 * атомарная установка GeoLite2-City.mmdb. Сеть — Http::fake; архив —
 * настоящий, собирается PharData прямо в тесте.
 */
class MaxmindUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    private string $targetPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/geo-test-'.uniqid());
        if (! is_dir($this->workDir)) {
            mkdir($this->workDir, 0755, true);
        }

        $this->targetPath = $this->workDir.'/GeoLite2-City.mmdb';

        config([
            'support_geo.maxmind_account_id' => '111111',
            'support_geo.maxmind_license_key' => 'test-license-key',
            'support_geo.maxmind_path' => $this->targetPath,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    /** Собирает настоящий GeoLite2-подобный tar.gz (вложенный каталог + mmdb). */
    private function makeFixtureArchive(int $mmdbSize = 4096): string
    {
        // Фикстура живёт в системном tmp: рабочая команда кладёт свои временные
        // файлы рядом с target, и тест «нет мусора рядом» не должен их путать.
        $base = tempnam(sys_get_temp_dir(), 'mmdbfix');
        @unlink((string) $base);
        $base .= '.fixture';
        $tar = $base.'.tar';

        $phar = new PharData($tar);
        $phar->addFromString('GeoLite2-City_20260824/GeoLite2-City.mmdb', str_repeat('M', $mmdbSize));
        $phar->addFromString('GeoLite2-City_20260824/LICENSE-DATA.txt', "fixture\n");
        $phar->compress(Phar::GZ);
        unset($phar);
        Phar::unlinkArchive($tar);

        $gz = $base.'.tar.gz';
        register_shutdown_function(fn () => @unlink($gz));

        return $gz;
    }

    public function test_fails_without_credentials_and_makes_no_request(): void
    {
        config(['support_geo.maxmind_account_id' => '', 'support_geo.maxmind_license_key' => '']);
        Http::fake();

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->targetPath);
    }

    public function test_installs_database_atomically(): void
    {
        $archive = $this->makeFixtureArchive();
        Http::fake(['*' => Http::response((string) file_get_contents($archive), 200)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(0);

        $this->assertFileExists($this->targetPath);
        $this->assertSame(str_repeat('M', 4096), (string) file_get_contents($this->targetPath));

        // Никакого мусора рядом (архив и staging не остаются на диске).
        $leftovers = array_diff(scandir($this->workDir) ?: [], ['.', '..', 'GeoLite2-City.mmdb']);
        $this->assertSame([], array_values($leftovers));
    }

    public function test_dry_run_leaves_working_file_untouched(): void
    {
        file_put_contents($this->targetPath, str_repeat('O', 4096));

        $archive = $this->makeFixtureArchive(2048);
        Http::fake(['*' => Http::response((string) file_get_contents($archive), 200)]);

        $this->artisan('support:geo-update-maxmind', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(str_repeat('O', 4096), (string) file_get_contents($this->targetPath));
    }

    public function test_archive_without_mmdb_entry_fails(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'mmdbfix');
        @unlink((string) $base);
        $base .= '.fixture';
        $tar = $base.'.tar';
        $phar = new PharData($tar);
        $phar->addFromString('README.txt', 'no database here');
        $phar->compress(Phar::GZ);
        unset($phar);
        Phar::unlinkArchive($tar);
        $archive = $base.'.tar.gz';
        register_shutdown_function(fn () => @unlink($archive));

        Http::fake(['*' => Http::response((string) file_get_contents($archive), 200)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);
        $this->assertFileDoesNotExist($this->targetPath);
    }

    public function test_http_error_is_reported(): void
    {
        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);
        $this->assertFileDoesNotExist($this->targetPath);
    }
}
