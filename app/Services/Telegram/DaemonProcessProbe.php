<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Support\ServerGuards\SystemInspector;

/**
 * Всё, что супервизору демона MadelineProto нужно знать о процессах машины.
 *
 * Отдельный интерфейс существует ровно затем же, зачем
 * {@see SystemInspector}: логика «этот демон живёт не
 * в своей cgroup / раздулся / протёк дескрипторами» обязана проверяться тестом
 * без Linux, /proc и systemd. Инцидент 19-08-2026 (H3121) стоил семи часов
 * простоя планировщика именно потому, что проверить это было нечем.
 */
interface DaemonProcessProbe
{
    /**
     * Пиды процессов, чья командная строка содержит $pattern (`pgrep -f`).
     *
     * @return list<int>
     */
    public function pidsMatching(string $pattern): array;

    /**
     * Путь cgroup процесса без префикса `0::` (/proc/<pid>/cgroup, v2).
     * null — процесса уже нет или cgroup недоступна.
     */
    public function cgroupOf(int $pid): ?string;

    /** cgroup самого супервизора — эталон «своей» группы. */
    public function ownCgroup(): ?string;

    /** VmRSS процесса в килобайтах; null — процесса нет. */
    public function rssKbOf(int $pid): ?int;

    /** Сколько открытых дескрипторов у процесса; null — не видно. */
    public function fdCountOf(int $pid): ?int;

    /** Возраст процесса в секундах; null — не видно. */
    public function ageSecondsOf(int $pid): ?int;

    /** Послать сигнал. true — сигнал доставлен. */
    public function signal(int $pid, int $signal): bool;

    public function isAlive(int $pid): bool;

    public function sleep(int $seconds): void;
}
