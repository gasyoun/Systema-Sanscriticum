<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\SplitGroupMath;
use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * @dataProvider timestampNames
     */
    public function test_timestamp_from_basename_parses_the_archive_name(string $name, ?string $wallClock): void
    {
        // Ожидание считается ЗДЕСЬ, в момент прогона, и в явной зоне имён
        // архивов (Y71). Раньше провайдер звал strtotime() на этапе discovery,
        // то есть ловил ambient-зону процесса ДО того, как Laravel её выставит,
        // и любой тест, оставивший Europe/Moscow, ронял эти три кейса на 10800 с.
        $expected = $wallClock === null
            ? null
            : (new DateTimeImmutable($wallClock, new DateTimeZone(SplitGroupMath::ARCHIVE_TIMEZONE)))->getTimestamp();

        $this->assertSame($expected, SplitGroupMath::timestampFromBasename($name));
    }

    /**
     * Разбор имени не должен зависеть от ambient-зоны процесса (Y71):
     * zulu-окружение, московское и максимальный сдвиг UTC+14 дают один и тот же
     * timestamp. Провал утечки зоны ловим assertion'ом на восстановление.
     */
    public function test_timestamp_from_basename_is_independent_of_ambient_timezone(): void
    {
        $name = '2026-08-23-02-02-32.part-01-of-39.zip';
        $original = date_default_timezone_get();
        $readings = [];

        try {
            foreach (['UTC', 'Europe/Moscow', 'Pacific/Kiritimati', 'America/Los_Angeles'] as $ambient) {
                date_default_timezone_set($ambient);
                $readings[$ambient] = SplitGroupMath::timestampFromBasename($name);
                $this->assertSame(
                    $ambient,
                    date_default_timezone_get(),
                    'Разбор имени архива подменил ambient-зону процесса.',
                );
            }
        } finally {
            date_default_timezone_set($original);
        }

        $this->assertSame($original, date_default_timezone_get(), 'Ambient-зона процесса протекла за пределы теста.');

        $moscow = (new DateTimeImmutable('2026-08-23 02:02:32', new DateTimeZone('Europe/Moscow')))->getTimestamp();

        $this->assertSame($moscow, $readings['Europe/Moscow'], 'МСК-настенные часы имени разобраны неверно.');
        foreach ($readings as $ambient => $timestamp) {
            $this->assertSame($moscow, $timestamp, sprintf('Ambient-зона %s повлияла на разбор имени архива.', $ambient));
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function timestampNames(): array
    {
        return [
            'single zip' => ['2026-08-13-14-27-49.zip', '2026-08-13 14:27:49'],
            'split part' => ['2026-08-23-02-02-32.part-01-of-39.zip', '2026-08-23 02:02:32'],
            'full path in' => ['Laravel/2026-08-23-02-02-32.part-39-of-39.zip', '2026-08-23 02:02:32'],
            'garbage name' => ['probe.readme.txt', null],
            'no timestamp' => ['backup.zip', null],
        ];
    }
}
