<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\StorageUsageService;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1345: дежурный по хранилищу. Покрыты все четыре ветки по образцу
 * CheckTelegramSupportSessionHealthTest — здоровый путь, алерт, --dry и
 * отсутствие получателей, — плюс арифметика сервиса на реальных файлах.
 */
class StorageUsageWatchdogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Кладёт файл заданного веса в наблюдаемый каталог внутри storage/app.
     */
    private function seedFile(string $relative, int $bytes): void
    {
        $path = storage_path('app/'.$relative);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, str_repeat('x', $bytes));
    }

    private function cleanup(string $relative): void
    {
        @unlink(storage_path('app/'.$relative));
    }

    protected function tearDown(): void
    {
        $this->cleanup('storage-watch-test/big.bin');
        @rmdir(storage_path('app/storage-watch-test'));
        @unlink(storage_path('logs/storage_check_series.jsonl'));

        parent::tearDown();
    }

    /** @test */
    public function healthy_storage_is_a_silent_success(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 100],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0, // отключаем: в CI число не показательно
        ]);

        $this->artisan('storage:check')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function directory_over_its_limit_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->seedFile('storage-watch-test/big.bin', 2 * 1048576); // 2 МБ

        config([
            'storage_watch.watched' => ['storage-watch-test' => 1], // потолок 1 МБ
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $this->artisan('storage:check')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function yellow_zone_alerts_before_the_limit_is_actually_hit(): void
    {
        // Смысл жёлтой зоны: предупредить ДО поломки, а не после.
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->seedFile('storage-watch-test/big.bin', (int) (0.9 * 1048576)); // 0.9 МБ

        config([
            'storage_watch.watched' => ['storage-watch-test' => 1],
            'storage_watch.warn_ratio' => 0.8,
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $snapshot = app(StorageUsageService::class)->snapshot();

        $this->assertFalse($snapshot['ok']);
        $this->assertSame('yellow', $snapshot['directories'][0]['level']);

        $this->artisan('storage:check')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function dry_run_reports_but_never_notifies(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        $this->seedFile('storage-watch-test/big.bin', 2 * 1048576);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 1],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $this->artisan('storage:check --dry')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function breach_without_any_admin_fails_loudly(): void
    {
        // Некому сказать — это провал команды, а не тихий успех: иначе
        // сторож молча деградирует в no-op при смене состава админов.
        $this->seedFile('storage-watch-test/big.bin', 2 * 1048576);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 1],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $this->artisan('storage:check')->assertFailed();
    }

    /** @test */
    public function unreadable_nested_directory_does_not_crash_the_watchdog(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX directory modes');
        }

        $dir = storage_path('app/storage-watch-test/secret');
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/x.bin', 'x');
        chmod($dir, 0000);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 100],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        try {
            $snapshot = app(StorageUsageService::class)->snapshot();
        } finally {
            chmod($dir, 0700);
            @unlink($dir.'/x.bin');
            @rmdir($dir);
            @rmdir(storage_path('app/storage-watch-test'));
        }

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('ok', $snapshot);
    }

    /** @test */
    public function missing_directory_measures_as_zero_not_as_an_error(): void
    {
        config([
            'storage_watch.watched' => ['storage-watch-test-does-not-exist' => 10],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $snapshot = app(StorageUsageService::class)->snapshot();

        $this->assertTrue($snapshot['ok']);
        $this->assertSame(0, $snapshot['directories'][0]['bytes']);
    }

    /** @test */
    public function truncated_scan_is_reported_instead_of_silently_undercounting(): void
    {
        // Предохранитель по количеству файлов не должен превращаться в
        // «всё хорошо»: недосчитанный вес обязан быть заявлен вслух.
        $this->seedFile('storage-watch-test/big.bin', 1024);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 100],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
            'storage_watch.scan_file_cap' => 1,
        ]);

        $snapshot = app(StorageUsageService::class)->snapshot();

        $this->assertTrue($snapshot['directories'][0]['truncated']);
        $this->assertFalse($snapshot['ok']);
        $this->assertStringContainsString('обход остановлен', implode(' ', $snapshot['alerts']));
    }

    /** @test */
    public function watchdog_is_scheduled_daily(): void
    {
        $event = $this->eventFor('storage:check');

        $this->assertNotNull($event, 'storage:check должен быть в расписании.');
        // dailyAt('04:20') → «20 4 * * *».
        $this->assertSame('20 4 * * *', $event->expression);
    }

    /** @test */
    public function daily_check_appends_one_jsonl_series_line(): void
    {
        // H4403: месячное окно калибровки H4291 должно оставлять данные,
        // а не молчание — одна разборываемая JSONL-строка за запуск.
        User::factory()->create(['role' => Roles::ADMIN]);
        $this->seedFile('storage-watch-test/big.bin', 2 * 1048576);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 1],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $this->artisan('storage:check')->assertSuccessful();

        $file = storage_path('logs/storage_check_series.jsonl');
        $this->assertFileExists($file);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        $this->assertCount(1, $lines, 'Один запуск — одна строка серии.');

        $row = json_decode($lines[0], true);
        $this->assertIsArray($row, 'Строка серии должна быть валидным JSON.');
        $this->assertArrayHasKey('at', $row);
        $this->assertSame(2 * 1048576, $row['directories'][0]['bytes']);
        $this->assertSame(1, $row['directories'][0]['limit_mb']);
        $this->assertSame('red', $row['directories'][0]['level']);
        $this->assertSame(100000, $row['total_limit_mb']);
        // total_bytes — весь storage/app, включая содержимое тестовой среды:
        // фиксируем только «int и не меньше замера наблюдаемого каталога».
        $this->assertIsInt($row['total_bytes']);
        $this->assertGreaterThanOrEqual($row['directories'][0]['bytes'], $row['total_bytes']);
        $this->assertFalse($row['ok']);
        // free_disk_bytes может быть null (unknown/off) — ключ обязан быть.
        $this->assertArrayHasKey('free_disk_bytes', $row);
        $this->assertArrayHasKey('free_disk_level', $row);
    }

    /** @test */
    public function dry_run_never_touches_the_series(): void
    {
        // Ручной --dry прогон — инструмент «посмотреть сейчас», а не
        // показатель суточной серии: запись серии не должна загрязняться.
        User::factory()->create(['role' => Roles::ADMIN]);

        config([
            'storage_watch.watched' => ['storage-watch-test' => 100],
            'storage_watch.total_mb' => 100000,
            'storage_watch.min_free_disk_mb' => 0,
        ]);

        $this->artisan('storage:check --dry')->assertSuccessful();

        $this->assertFileDoesNotExist(storage_path('logs/storage_check_series.jsonl'));
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
