<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\SplitGroupMath;
use PHPUnit\Framework\TestCase;

class SplitGroupMathTest extends TestCase
{
    public function test_parse_groups_tracks_indices_and_detects_total_conflict(): void
    {
        $groups = SplitGroupMath::parseGroups([
            '2026-08-23-10-00-00.part-01-of-03.zip',
            '2026-08-23-10-00-00.part-03-of-03.zip',
            '2026-08-23-11-00-00.part-01-of-02.zip',
            '2026-08-23-11-00-00.part-01-of-05.zip', // разнобой total — мусор
        ]);

        $this->assertSame(['total' => 3, 'indices' => [1 => true, 3 => true]], [
            'total' => $groups['2026-08-23-10-00-00']['total'],
            'indices' => $groups['2026-08-23-10-00-00']['indices'],
        ]);
        $this->assertSame(-1, $groups['2026-08-23-11-00-00']['total'], 'Разнобой total обязан помечать группу битой.');
    }

    public function test_is_complete_requires_every_index(): void
    {
        $full = ['total' => 3, 'indices' => [1 => true, 2 => true, 3 => true]];
        $gap = ['total' => 3, 'indices' => [1 => true, 3 => true]];
        $corrupt = ['total' => -1, 'indices' => [1 => true]];

        $this->assertTrue(SplitGroupMath::isComplete($full));
        $this->assertFalse(SplitGroupMath::isComplete($gap), 'Дырка в середине группы — группа неполна.');
        $this->assertFalse(SplitGroupMath::isComplete($corrupt));
    }

    public function test_newest_complete_entry_ignores_lone_full_size_part(): void
    {
        // Класс H3371: одинокая часть ровно max_part_mb проходит порог
        // BACKUP_MIN_ARCHIVE_MB и раньше читалась как живой off-site.
        $entries = [
            ['name' => '2026-08-22-01-00-00.zip', 'timestamp' => 1000, 'bytes' => 500],
            ['name' => '2026-08-23-09-00-00.part-02-of-39.zip', 'timestamp' => 9000, 'bytes' => 52 * 1024 * 1024],
        ];

        $best = SplitGroupMath::newestCompleteEntry($entries);

        $this->assertNotNull($best);
        $this->assertSame(1000, $best['timestamp'], 'Одинокая часть не должна считаться свежим архивом.');
        $this->assertSame(500, $best['bytes']);
    }

    public function test_newest_complete_entry_sums_group_bytes_and_beats_older_standalone(): void
    {
        $entries = [
            ['name' => '2026-08-22-01-00-00.zip', 'timestamp' => 1000, 'bytes' => 500],
            ['name' => '2026-08-23-02-00-00.part-01-of-02.zip', 'timestamp' => 2000, 'bytes' => 300],
            ['name' => '2026-08-23-02-00-00.part-02-of-02.zip', 'timestamp' => 2500, 'bytes' => 700],
            ['name' => '2026-08-21-00-00-00.part-01-of-02.zip', 'timestamp' => 50, 'bytes' => 40 * 1024 * 1024],
        ];

        $best = SplitGroupMath::newestCompleteEntry($entries);

        $this->assertSame(['timestamp' => 2500, 'bytes' => 1000], $best, 'Полная группа бьёт старый одиночный архив; байты суммируются.');
    }

    public function test_newest_complete_entry_returns_null_when_nothing_is_complete(): void
    {
        $entries = [
            ['name' => '2026-08-23-02-00-00.part-38-of-39.zip', 'timestamp' => 9000, 'bytes' => 300],
            ['name' => '2026-08-23-02-00-00.part-39-of-39.zip', 'timestamp' => 9100, 'bytes' => 400],
        ];

        $this->assertNull(SplitGroupMath::newestCompleteEntry($entries), 'Только куски оборванной группы — свежести нет.');
    }
}
