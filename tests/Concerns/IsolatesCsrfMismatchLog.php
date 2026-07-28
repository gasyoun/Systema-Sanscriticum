<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * #824 — до этого трейта все тесты вокруг канала `csrf_mismatch` работали с
 * ОДНИМ литеральным путём `storage/logs/csrf-mismatch-*.log`, а `paratest`
 * выдаёт каждому из N процессов свою `:memory:`-базу и НИЧЕГО для `storage/`.
 * Поэтому purge в одном процессе удалял посев другого:
 *
 *   - purge между `seedMismatches(10)` и запуском команды → дайджест видел 0
 *     записей, порога не достигал, выходил с кодом 0 → `assertFailed()` падал
 *     как «0 is not equal to 0»;
 *   - посев соседа переживал purge → телеметрия видела лишние строки →
 *     `assertCount(1)` падал как «size 0 matches expected size 1».
 *
 * Гонка симметрична, поэтому давала и ложный ЗЕЛЁНЫЙ: полный `--parallel`
 * прогон 28-07-2026 показал 2433/2433, а следующий на почти том же дереве
 * упал. Гейт, зелёный по удаче планировщика, хуже отсутствующего.
 *
 * У той же хрупкости есть однопроцессный двойник на Windows: `@unlink` не
 * удаляет файл с живым дескриптором Monolog, поэтому записи класса,
 * отработавшего раньше, утекали в чужой счёт (так H1771-й
 * `CsrfMismatchGracefulByDefaultTest`, сортирующийся перед
 * `CsrfMismatchTelemetryTest`, ломал его на ровном месте).
 *
 * Оба режима закрываются одним и тем же: каталог лога уникален по
 * (процесс × тест-класс), так что делить его физически не с кем. Ключ берётся
 * из `UNIQUE_TEST_TOKEN`/`TEST_TOKEN` — переменных, которые paratest кладёт в
 * окружение каждого воркера (`Options::ENV_KEY_UNIQUE_TOKEN`/`ENV_KEY_TOKEN`),
 * — с откатом на PID для обычного последовательного прогона. Класс в ключе
 * нужен отдельно: при `--functional` paratest раскидывает по процессам
 * МЕТОДЫ, и один класс может оказаться разорван между воркерами.
 *
 * Продакшен-кода это не касается: `CsrfMismatchDigestService::logFiles()` уже
 * выводит свою маску из `config('logging.channels.csrf_mismatch.path')`, и
 * трейт просто переопределяет эту конфигурацию. Маска здесь выводится ТЕМ ЖЕ
 * способом намеренно — если продакшен сменит схему именования, тесты обязаны
 * поехать вместе с ним, а не молча разойтись.
 */
trait IsolatesCsrfMismatchLog
{
    /**
     * Уводит канал `csrf_mismatch` в каталог, принадлежащий только этому
     * процессу и этому классу. Вызывать из `setUp()` ПОСЛЕ `parent::setUp()`.
     */
    protected function isolateCsrfMismatchLog(): void
    {
        $token = (string) (
            $_SERVER['UNIQUE_TEST_TOKEN']
            ?? $_ENV['UNIQUE_TEST_TOKEN']
            ?? $_SERVER['TEST_TOKEN']
            ?? $_ENV['TEST_TOKEN']
            ?? getmypid()
        );
        $dir = storage_path('logs/testing/csrf-mismatch/'.$token.'/'.class_basename(static::class));

        // @-глушение и повторная проверка, а не `if (! is_dir()) mkdir()`:
        // листовой каталог у каждого воркера свой, но РОДИТЕЛЬСКИЕ общие, и
        // рекурсивный mkdir из двух процессов одновременно даёт «File exists».
        // Ловить гонку в самом лекарстве от гонок было бы обидно.
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог лога для теста: {$dir}");
        }

        config(['logging.channels.csrf_mismatch.path' => $dir.'/csrf-mismatch.log']);

        $this->purgeCsrfMismatchLogs();
    }

    /**
     * Ровно та же деривация, что у CsrfMismatchDigestService::logFiles().
     *
     * @return list<string>
     */
    protected function csrfMismatchLogFiles(): array
    {
        [$dir, $name, $ext] = $this->csrfMismatchPathParts();

        return array_values(glob($dir.'/'.$name.'-*.'.$ext) ?: []);
    }

    /**
     * Путь, который драйвер `daily` использует для СЕГОДНЯШНИХ записей —
     * точка посева фикстур.
     */
    protected function csrfMismatchLogPathForToday(): string
    {
        [$dir, $name, $ext] = $this->csrfMismatchPathParts();

        return $dir.'/'.$name.'-'.now()->format('Y-m-d').'.'.$ext;
    }

    protected function purgeCsrfMismatchLogs(): void
    {
        foreach ($this->csrfMismatchLogFiles() as $file) {
            @unlink($file);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function csrfMismatchPathParts(): array
    {
        $configured = (string) config(
            'logging.channels.csrf_mismatch.path',
            storage_path('logs/csrf-mismatch.log')
        );
        $info = pathinfo($configured);

        return [
            $info['dirname'] ?? storage_path('logs'),
            $info['filename'] ?? 'csrf-mismatch',
            $info['extension'] ?? 'log',
        ];
    }
}
