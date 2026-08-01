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
     * Пути tracked-файлов с локальными правками в $repoDir
     * (`git status --porcelain --untracked-files=no`). null — git недоступен.
     *
     * @return list<string>|null
     */
    public function trackedDirtyPaths(string $repoDir): ?array;
}
