<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3445/H6602 — support:geo-update-maxmind: скачивание DB-IP City Lite
 * (.mmdb.gz, без регистрации), распаковка gzip и атомарная установка базы.
 * Сеть — Http::fake; архив — настоящий gzip, собирается прямо в тесте.
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

        config(['support_geo.maxmind_path' => $this->targetPath]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    /** URL месячного файла DB-IP City Lite, который запросит команда. */
    private function monthUrl(string $month): string
    {
        return 'https://download.db-ip.com/free/dbip-city-lite-'.$month.'.mmdb.gz';
    }

    /** Настоящая .mmdb-подобная база в gzip: тело + маркер метаданных MaxMind DB. */
    private function makeFixtureGz(int $mmdbSize = 4096): string
    {
        $mmdb = str_repeat('M', $mmdbSize)."\xAB\xCD\xEFMaxMind.com".'{"database_type":"DBIP.City.Lite"}';
        $gz = $this->workDir.'/fixture-'.uniqid().'.mmdb.gz';
        file_put_contents($gz, (string) gzencode($mmdb));
        register_shutdown_function(fn () => @unlink($gz));

        return $gz;
    }

    public function test_installs_database_atomically(): void
    {
        $gz = $this->makeFixtureGz();
        Http::fake([$this->monthUrl(now()->format('Y-m')) => Http::response((string) file_get_contents($gz), 200)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(0);

        $this->assertFileExists($this->targetPath);
        $expected = gzdecode((string) file_get_contents($gz));
        $this->assertSame($expected, (string) file_get_contents($this->targetPath));

        // Никакого мусора рядом (архив и staging не остаются на диске).
        $leftovers = array_diff(scandir($this->workDir) ?: [], ['.', '..', 'GeoLite2-City.mmdb']);
        $this->assertSame([], array_values(array_filter($leftovers, fn ($f) => ! str_starts_with((string) $f, 'fixture-'))));
    }

    public function test_falls_back_to_previous_month_when_current_missing(): void
    {
        $gz = $this->makeFixtureGz(2048);
        Http::fake([
            $this->monthUrl(now()->format('Y-m')) => Http::response('not found', 404),
            $this->monthUrl(now()->subMonth()->format('Y-m')) => Http::response((string) file_get_contents($gz), 200),
        ]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(0);

        $this->assertFileExists($this->targetPath);
        $this->assertSame(gzdecode((string) file_get_contents($gz)), (string) file_get_contents($this->targetPath));
    }

    public function test_dry_run_leaves_working_file_untouched(): void
    {
        file_put_contents($this->targetPath, str_repeat('O', 4096));

        $gz = $this->makeFixtureGz(2048);
        Http::fake([$this->monthUrl(now()->format('Y-m')) => Http::response((string) file_get_contents($gz), 200)]);

        $this->artisan('support:geo-update-maxmind', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(str_repeat('O', 4096), (string) file_get_contents($this->targetPath));
    }

    public function test_payload_without_mmdb_marker_fails(): void
    {
        $gz = $this->workDir.'/fixture-bad-'.uniqid().'.mmdb.gz';
        file_put_contents($gz, (string) gzencode(str_repeat('x', 4096)));
        register_shutdown_function(fn () => @unlink($gz));

        // Оба месяца получают один и тот же битый payload — фолбэк не спасает.
        Http::fake(['*' => Http::response((string) file_get_contents($gz), 200)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);
        $this->assertFileDoesNotExist($this->targetPath);
    }

    public function test_http_error_on_all_months_is_reported(): void
    {
        Http::fake(['*' => Http::response('server error', 500)]);

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);
        $this->assertFileDoesNotExist($this->targetPath);
    }

    public function test_empty_target_path_fails_without_request(): void
    {
        config(['support_geo.maxmind_path' => '']);
        Http::fake();

        $this->artisan('support:geo-update-maxmind')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->targetPath);
    }
}
