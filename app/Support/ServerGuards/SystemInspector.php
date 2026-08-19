<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

/**
 * Всё, что аудитору нужно знать о машине. Отдельный интерфейс существует ровно
 * для того, чтобы логика проверок жила в тестах без Linux, systemd и cron.
 */
interface SystemInspector
{
    public function fileContents(string $path): ?string;

    public function fileExists(string $path): bool;

    public function fileMode(string $path): ?string;

    public function crontabFor(string $user): ?string;

    public function unitIsActive(string $unit): bool;

    /**
     * Значение свойства юнита (systemctl show -p <property> --value).
     */
    public function unitProperty(string $unit, string $property): ?string;

    /**
     * Действующий memory_limit CLI-процесса PHP.
     */
    public function phpCliMemoryLimit(): string;

    /**
     * @return list<string>
     */
    public function globFiles(string $pattern): array;

    /**
     * Байты свопа; null — если узнать нельзя.
     */
    public function swapTotalBytes(): ?int;

    /**
     * Имена юнитов в состоянии failed (`systemctl --failed`).
     *
     * null — спросить нечем (не Linux, нет systemd). Пустой список — здоровая
     * машина. Отличать одно от другого обязательно: «не спросили» и «всё
     * хорошо» — разные ответы, и подмена первого вторым как раз и держала
     * samudra-health-monitor мёртвым пять суток.
     *
     * @return list<string>|null
     */
    public function failedUnits(): ?array;

    /**
     * Состояние каждого назначения резервных копий из config/backup.php.
     *
     * `newestAt` — unix-время новейшего архива, `newestBytes` — его размер;
     * оба null, если архивов нет вовсе. null вместо всего списка — Spatie не
     * отвечает (сеть, права, отключённый пакет): проверка обязана молчать, а не
     * падать.
     *
     * @return list<array{disk: string, reachable: bool, newestAt: int|null, newestBytes: int|null}>|null
     */
    public function backupDestinations(): ?array;

    /**
     * Пути tracked-файлов с локальными правками в $repoDir
     * (`git status --porcelain --untracked-files=no`). null — git недоступен.
     *
     * @return list<string>|null
     */
    public function trackedDirtyPaths(string $repoDir): ?array;
}
