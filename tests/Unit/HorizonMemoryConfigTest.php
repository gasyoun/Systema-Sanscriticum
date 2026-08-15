<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Страж памяти Horizon.
 *
 * Мастер-супервизор поднимает то же приложение, что и воркеры, поэтому лимит
 * ниже самого скромного воркера означает вечный цикл «перевалил — умер —
 * поднялся». На проде это и случилось: 15-08-2026 при `memory_limit => 64`
 * лог держал 5050 записей «Using 65/64MB» за восемь часов. Рвались задачи, а
 * health-чек авто-деплоя, попадая в окно перезапуска, откатывал здоровые
 * выкатки. Пусть падает тест, а не очередь.
 */
class HorizonMemoryConfigTest extends TestCase
{
    /** @test */
    public function the_master_gets_at_least_as_much_memory_as_the_leanest_worker(): void
    {
        $master = (int) config('horizon.memory_limit');

        $workers = collect((array) config('horizon.defaults'))
            ->pluck('memory')
            ->filter()
            ->map(fn ($memory): int => (int) $memory);

        $this->assertNotEmpty($workers, 'без воркеров сравнивать не с чем — конфиг Horizon пуст');
        $this->assertGreaterThan(0, $master, 'лимит мастера обязан быть задан');
        $this->assertGreaterThanOrEqual(
            $workers->min(),
            $master,
            "мастеру выделено {$master} МБ при самом скромном воркере на {$workers->min()} МБ — ".
            'мастер будет перезапускаться по кругу и ронять health-чек деплоя',
        );
    }

    /** @test */
    public function every_worker_declares_its_own_memory(): void
    {
        // Воркер без memory берёт дефолт artisan queue:work (128) молча —
        // расхождение конфига и реальности всплывает только на проде.
        foreach ((array) config('horizon.defaults') as $name => $supervisor) {
            $this->assertArrayHasKey('memory', (array) $supervisor, "у супервизора «{$name}» не задан memory");
            $this->assertGreaterThan(0, (int) $supervisor['memory'], "у супервизора «{$name}» memory нулевой");
        }
    }
}
