<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServerGuards\SoftFailureFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoftFailureFingerprintTest extends TestCase
{
    public function test_fuse_timestamp_and_sha_do_not_change_hash(): void
    {
        $a = 'guards/auto-deploy: авто-деплой остановлен предохранителем (2026-08-05T09:00:01Z [blocked-preflight] auto-retry … HEAD 50e751b5) — разобраться и удалить /var/www/html/storage/auto_deploy.disabled';
        $b = 'guards/auto-deploy: авто-деплой остановлен предохранителем (2026-08-06T18:00:29Z [blocked-preflight] deploy.sh код 1 HEAD a1b2c3d) — разобраться и удалить /var/www/html/storage/auto_deploy.disabled';

        $this->assertSame(
            SoftFailureFingerprint::hash([['message' => $a, 'severity' => 'soft']]),
            SoftFailureFingerprint::hash([['message' => $b, 'severity' => 'soft']]),
        );
        $this->assertSame('guards/auto-deploy:fuse:blocked-preflight', SoftFailureFingerprint::normalizeMessage($a));
    }

    public function test_rolled_back_is_distinct_from_blocked_preflight(): void
    {
        $pre = SoftFailureFingerprint::normalizeMessage(
            'guards/auto-deploy: … (2026-08-05T05:30:30Z [blocked-preflight] …)'
        );
        $rb = SoftFailureFingerprint::normalizeMessage(
            'guards/auto-deploy: … (2026-08-05T05:30:30Z [rolled-back] …)'
        );
        $this->assertSame('guards/auto-deploy:fuse:blocked-preflight', $pre);
        $this->assertSame('guards/auto-deploy:fuse:rolled-back', $rb);
        $this->assertNotSame($pre, $rb);
    }

    public function test_order_independent_set(): void
    {
        $msgs = [
            ['message' => 'hybrid /library: route missing', 'severity' => 'soft'],
            ['message' => 'guards/auto-deploy: (TS [timeout-alive] x)', 'severity' => 'soft'],
        ];
        $rev = array_reverse($msgs);
        $this->assertSame(
            SoftFailureFingerprint::hash($msgs),
            SoftFailureFingerprint::hash($rev),
        );
    }

    #[DataProvider('dirtyPathProvider')]
    public function test_dirty_paths_normalized(string $message, string $expected): void
    {
        $this->assertSame($expected, SoftFailureFingerprint::normalizeMessage($message));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dirtyPathProvider(): array
    {
        return [
            'single' => [
                'guards/auto-deploy: tracked dirty на проде (deploy.sh/auto-deploy остановятся): config/marathon_landing_copy.php — код только через main',
                'guards/auto-deploy:dirty:config/marathon_landing_copy.php',
            ],
            'sorted' => [
                'guards/auto-deploy: tracked dirty на проде (deploy.sh/auto-deploy остановятся): z.php, a.php — код',
                'guards/auto-deploy:dirty:a.php,z.php',
            ],
        ];
    }

    public function test_cgroup_rss_mib_does_not_change_hash(): void
    {
        $a = 'guards/cgroup: cron.service занял 2029 МиБ из 2048 МиБ (≥80 %) — до троттлинга близко';
        $b = 'guards/cgroup: cron.service занял 1802 МиБ из 2048 МиБ (≥80 %) — до троттлинга близко';
        $this->assertSame(
            SoftFailureFingerprint::hash([['message' => $a, 'severity' => 'soft']]),
            SoftFailureFingerprint::hash([['message' => $b, 'severity' => 'soft']]),
        );
        $this->assertSame('guards/cgroup', SoftFailureFingerprint::normalizeMessage($a));
    }

    public function test_backup_size_and_age_collapse_to_guard_name(): void
    {
        $a = 'guards/backup-fresh: новейший архив на off-site диске yandex_disk — 11 МиБ при пороге 200 МиБ';
        $b = 'guards/backup-fresh: новейший архив на off-site диске yandex_disk — 12 МиБ при пороге 200 МиБ';
        $this->assertSame(
            SoftFailureFingerprint::hash([['message' => $a]]),
            SoftFailureFingerprint::hash([['message' => $b]]),
        );
        $this->assertSame('guards/backup-fresh', SoftFailureFingerprint::normalizeMessage($a));
        $this->assertSame(
            'guards/tmpfs-cap',
            SoftFailureFingerprint::normalizeMessage(
                'guards/tmpfs-cap: /tmp смонтирован tmpfs БЕЗ явного size='
            ),
        );
    }

    public function test_host_ops_set_stable_when_only_cgroup_rss_moves(): void
    {
        $tmpfs = 'guards/tmpfs-cap: /tmp смонтирован tmpfs БЕЗ явного size= — потолок хоста';
        $backup = 'guards/backup-fresh: нет ни одного живого off-site назначения';
        $setA = [
            ['message' => 'guards/cgroup: cron.service занял 2029 МиБ из 2048 МиБ (≥80 %)', 'severity' => 'soft'],
            ['message' => $tmpfs, 'severity' => 'critical'],
            ['message' => $backup, 'severity' => 'critical'],
        ];
        $setB = [
            ['message' => 'guards/cgroup: cron.service занял 1802 МиБ из 2048 МиБ (≥80 %)', 'severity' => 'soft'],
            ['message' => $tmpfs, 'severity' => 'critical'],
            ['message' => $backup, 'severity' => 'critical'],
        ];
        $this->assertSame(SoftFailureFingerprint::hash($setA), SoftFailureFingerprint::hash($setB));
    }

    /**
     * 25-11-2026: every failing systemd unit collapsed to `guards/failed-units`,
     * so two different broken units shared fingerprint 6fd69840 and the second
     * one was invisible behind the first one's open alert.
     */
    public function test_failed_units_are_one_fingerprint_per_unit(): void
    {
        $telegram = 'guards/failed-units: telegram-daily.service в состоянии failed — '
            .'`systemctl status telegram-daily.service` и `journalctl -u telegram-daily.service -n 50`. '
            .'Осознанно терпимый провал вносить в FAILED_UNITS_ALLOWLIST с причиной, а не удалять проверку';
        $socks = 'guards/failed-units: systema-socks91-watch.service в состоянии failed — '
            .'`systemctl status systema-socks91-watch.service` и `journalctl -u systema-socks91-watch.service -n 50`. '
            .'Осознанно терпимый провал вносить в FAILED_UNITS_ALLOWLIST с причиной, а не удалять проверку';

        $this->assertSame('guards/failed-units:telegram-daily', SoftFailureFingerprint::normalizeMessage($telegram));
        $this->assertSame('guards/failed-units:systema-socks91-watch', SoftFailureFingerprint::normalizeMessage($socks));
        $this->assertNotSame(
            SoftFailureFingerprint::hash([['message' => $telegram, 'severity' => 'soft']]),
            SoftFailureFingerprint::hash([['message' => $socks, 'severity' => 'soft']]),
        );

        // Same unit, different guidance text => same fingerprint (no re-fire).
        $telegramAgain = 'guards/failed-units: telegram-daily.service в состоянии failed — перепроверить вручную';
        $this->assertSame(
            SoftFailureFingerprint::hash([['message' => $telegram]]),
            SoftFailureFingerprint::hash([['message' => $telegramAgain]]),
        );
    }
}
