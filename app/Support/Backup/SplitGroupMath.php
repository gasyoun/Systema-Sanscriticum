<?php

declare(strict_types=1);

namespace App\Support\Backup;

/**
 * Чистая математика групп частей split-upload: разбор имён
 * `<stem>.part-NN-of-NN.zip`, полнота группы, новейшая ПОЛНАЯ запись.
 *
 * Живёт отдельно от SplitUploadToYandex, чтобы свежестный аудит
 * (ShellSystemInspector) и сам слушатель считали одинаково и никто из них
 * не тянул файловую логику другого.
 */
final class SplitGroupMath
{
    public const PART_PATTERN = '/^(?<stem>\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.part-(?<index>\d+)-of-(?<total>\d+)\.zip$/';

    /**
     * Сгруппировать имена файлов-частей по стволу.
     *
     * @param  list<string>  $basenames
     * @return array<string, array{total: int, indices: array<int, bool>}>
     */
    public static function parseGroups(array $basenames): array
    {
        $groups = [];

        foreach ($basenames as $name) {
            if (preg_match(self::PART_PATTERN, $name, $m) !== 1) {
                continue;
            }

            $index = (int) $m['index'];
            $total = (int) $m['total'];
            $groups[$m['stem']] ??= ['total' => $total, 'indices' => []];

            // Разнобой total внутри одного ствола — мусор/повреждение,
            // такая группа никогда не считается полной.
            if ($groups[$m['stem']]['total'] !== $total) {
                $groups[$m['stem']]['total'] = -1;
            }

            $groups[$m['stem']]['indices'][$index] = true;
        }

        return $groups;
    }

    /**
     * @param  array{total: int, indices: array<int, bool>}  $group
     */
    public static function isComplete(array $group): bool
    {
        if ($group['total'] < 1) {
            return false;
        }

        for ($i = 1; $i <= $group['total']; $i++) {
            if (! isset($group['indices'][$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Новейшая ПОЛНАЯ запись из списка файлов диска.
     *
     * Одиночный zip без .part- — полная запись сам по себе; группа частей
     * полна только когда все индексы 1..total на месте. Одиночная часть
     * (в том числе ровно max_part_mb байт — класс, проходящий порог
     * BACKUP_MIN_ARCHIVE_MB) полным архивом НЕ считается: H3371 показал,
     * что оборванная группа оставляет такие части на диске неделями.
     *
     * @param  list<array{name: string, timestamp: int, bytes: int}>  $entries
     * @return array{timestamp: int, bytes: int}|null
     */
    public static function newestCompleteEntry(array $entries): ?array
    {
        $best = null;
        $groups = [];

        foreach ($entries as $entry) {
            $base = basename($entry['name']);

            if (preg_match(self::PART_PATTERN, $base, $m) !== 1) {
                if ($best === null || $entry['timestamp'] > $best['timestamp']) {
                    $best = ['timestamp' => $entry['timestamp'], 'bytes' => $entry['bytes']];
                }

                continue;
            }

            $stem = $m['stem'];
            $groups[$stem] ??= ['total' => null, 'indices' => [], 'timestamp' => 0, 'bytes' => 0];

            if ($groups[$stem]['total'] !== null && $groups[$stem]['total'] !== (int) $m['total']) {
                $groups[$stem]['total'] = -1;
            } elseif ($groups[$stem]['total'] === null) {
                $groups[$stem]['total'] = (int) $m['total'];
            }

            $groups[$stem]['indices'][(int) $m['index']] = true;
            $groups[$stem]['timestamp'] = max($groups[$stem]['timestamp'], $entry['timestamp']);
            $groups[$stem]['bytes'] += $entry['bytes'];
        }

        foreach ($groups as $group) {
            if (! self::isComplete(['total' => $group['total'], 'indices' => $group['indices']])) {
                continue;
            }

            if ($best === null || $group['timestamp'] > $best['timestamp']) {
                $best = ['timestamp' => $group['timestamp'], 'bytes' => $group['bytes']];
            }
        }

        return $best;
    }
}
