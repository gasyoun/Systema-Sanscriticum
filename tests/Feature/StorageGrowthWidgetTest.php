<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\StorageGrowthWidget;
use App\Models\User;
use App\Services\StorageUsageService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H4298 — виджет «Хранилище: рост и прогноз» (MG 07-09-2026: «к какому
 * месяцу исчерпается место? Это должно быть видно в админке»).
 *
 * 07-09 замер на проде: homework 1,37 ГБ (июн 63 → июл 263 → авг 990 →
 * сен 61 МБ) — 27% от 5-гигабайтной сигнальной линии показались MG
 * «опасно быстро», и правильный ответ — не спорить, а показать траекторию
 * прямо в дашборде.
 */
class StorageGrowthWidgetTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('storage_growth_widget.v1');
        config([
            'storage_watch.watched' => ['growth-test' => 100],
            'storage_watch.total_mb' => 500,
            'storage_watch.min_free_disk_mb' => 0,
        ]);
        $this->dir = storage_path('app/growth-test');
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        Cache::forget('storage_growth_widget.v1');

        parent::tearDown();
    }

    private function touchFile(string $name, int $sizeKb, int $mtime): void
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, str_repeat('x', $sizeKb * 1024));
        touch($path, $mtime);
    }

    /** @test */
    public function growth_buckets_files_by_mtime_and_counts_recent_window(): void
    {
        $now = time();
        $this->touchFile('fresh.bin', 100, $now - 5 * 86400);      // в окне 30 дней
        $this->touchFile('last-month.bin', 200, $now - 40 * 86400); // вне окна
        $this->touchFile('old.bin', 400, $now - 70 * 86400);        // вне окна

        $growth = app(StorageUsageService::class)->growth('growth-test');

        $this->assertSame(3, $growth['files']);
        $this->assertFalse($growth['truncated']);
        $this->assertSame(100 * 1024, $growth['recent_bytes']);
        $this->assertSame(30, $growth['recent_days']);

        // Свежий файл — в текущем месяце, старые — в своих.
        $this->assertSame(
            100 * 1024,
            $growth['months'][now()->format('Y-m')] ?? 0,
            'свежий файл должен попасть в текущий месяц'
        );
        $total = array_sum($growth['months']);
        $this->assertSame(700 * 1024, $total);
    }

    /** @test */
    public function growth_respects_file_cap_and_reports_truncation(): void
    {
        config(['storage_watch.scan_file_cap' => 2]);

        $now = time();
        $this->touchFile('a.bin', 10, $now - 3600);
        $this->touchFile('b.bin', 10, $now - 3600);
        $this->touchFile('c.bin', 10, $now - 3600);

        $growth = app(StorageUsageService::class)->growth('growth-test');

        $this->assertTrue($growth['truncated']);
        $this->assertSame(2, $growth['files']);
    }

    /** @test */
    public function growth_of_missing_directory_is_zero_not_error(): void
    {
        $growth = app(StorageUsageService::class)->growth('no-such-dir');

        $this->assertSame(0, $growth['files']);
        $this->assertSame(0, $growth['recent_bytes']);
        $this->assertFalse($growth['truncated']);
    }

    /** @test */
    public function eta_labels_are_honest_about_each_state(): void
    {
        $widget = app(StorageGrowthWidget::class);

        $this->assertSame('превышен', $widget->etaLabel(150 * 1048576, 100, 10 * 1048576));
        $this->assertSame('не растёт', $widget->etaLabel(10 * 1048576, 100, 0));
        $this->assertSame('> 5 лет', $widget->etaLabel(10 * 1048576, 100, 1024));
        $this->assertSame('—', $widget->etaLabel(10 * 1048576, 0, 10 * 1048576));
    }

    /** @test */
    public function eta_month_is_the_ceil_of_months_left(): void
    {
        // Осталось 60 МБ, темп 30 МБ/мес → 2 месяца, ceil = 2 (пессимистично рано).
        $widget = app(StorageGrowthWidget::class);
        $eta = now()->copy()->addMonths(2);

        $this->assertSame(
            '~'.match ((int) $eta->format('n')) {
                1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'май', 6 => 'июн',
                7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', default => 'дек',
            }.' '.$eta->format('Y'),
            $widget->etaLabel(40 * 1048576, 100, 30 * 1048576)
        );
    }

    /** @test */
    public function widget_view_renders_rows_and_total(): void
    {
        $now = time();
        $this->touchFile('fresh.bin', 100, $now - 5 * 86400);

        $data = app(StorageGrowthWidget::class)->getViewData();

        $this->assertNotEmpty($data['rows']);
        $this->assertSame('growth-test', $data['rows'][0]['path']);
        $this->assertArrayHasKey('eta', $data['total']);

        // Живой рендер виджета под админом (canView гейтится RoleGate).
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Livewire::actingAs($admin)
            ->test(StorageGrowthWidget::class)
            ->assertSuccessful()
            ->assertSee('growth-test')
            ->assertSee('ВСЕГО storage/app')
            ->assertSee('Прогноз');
    }
}
