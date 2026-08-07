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
}
