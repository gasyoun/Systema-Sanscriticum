<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\MadelineSyncBreaker;
use App\Services\Telegram\MadelineSyncPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4691 (E002-C2, 2nd wave): the MTProto watchdog-kill loop under the shared
 * sentinel breaker. The breaker's own math is pinned in Uprava
 * (tools/test_sentinel_breaker.py); this test pins the WIRING:
 *
 *  * every post-timeout cooldown records one kill in the breaker CLI;
 *  * a healthy session (no kill state on disk) never spawns the CLI;
 *  * a frozen breaker makes cooldownActive() true, so the live sync SKIPS;
 *  * a missing library is fail-open (cooldown-only, as before H4691);
 *  * the scream command delivers to the cabinet-probe critical chat.
 *
 * The CLI is a fake shell script (argv log + a control file for the exit
 * code). When SENTINEL_BREAKER_REAL_BIN points at the real Uprava library, one
 * more case runs the genuine freeze end to end.
 */
class MadelineSyncBreakerTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('fake breaker CLI is a POSIX shell script');
        }
        $this->tmp = sys_get_temp_dir().'/h4691-breaker-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/state');
        File::put($this->tmp.'/fake.sh', "#!/bin/sh\n"
            ."echo \"\$*\" >> \"{$this->tmp}/calls\"\n"
            ."echo \"\$SENTINEL_BREAKER_ALARM_CMD\" > \"{$this->tmp}/alarm_cmd\"\n"
            ."[ -f \"{$this->tmp}/refuse\" ] && [ \"\$1\" = check ] && exit 1\n"
            ."exit 0\n");
        config([
            'services.sentinel_breaker.enabled' => true,
            'services.sentinel_breaker.python' => '/bin/sh',
            'services.sentinel_breaker.bin' => $this->tmp.'/fake.sh',
            'services.sentinel_breaker.state_dir' => $this->tmp.'/state',
            'services.telegram_support.sync_timeout_cooldown_seconds' => 600,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function calls(): array
    {
        return is_file($this->tmp.'/calls') ? file($this->tmp.'/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    private function plantKill(): void
    {
        File::ensureDirectoryExists($this->tmp.'/state/madeline_sync');
        File::put($this->tmp.'/state/madeline_sync/actions.log', "1790000000\n");
    }

    public function test_post_timeout_cooldown_records_one_kill_in_the_breaker(): void
    {
        MadelineSyncPhase::armCooldown(600);

        $calls = $this->calls();
        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('record madeline_sync ', $calls[0]);
        $this->assertStringContainsString('--class self_kill', $calls[0]);
        $this->assertStringContainsString('--state-dir '.$this->tmp.'/state', $calls[0]);
    }

    public function test_healthy_session_never_spawns_the_cli(): void
    {
        $this->assertFalse(MadelineSyncPhase::cooldownActive());
        $this->assertSame([], $this->calls());
    }

    public function test_frozen_breaker_skips_the_live_sync(): void
    {
        $this->plantKill();
        touch($this->tmp.'/refuse');

        $this->assertTrue(MadelineSyncPhase::cooldownActive());
        $this->assertStringStartsWith('check madeline_sync ', $this->calls()[0]);
        $this->assertStringContainsString('guards:breaker-alarm', (string) file_get_contents($this->tmp.'/alarm_cmd'));

        $this->artisan('telegram-support:sync')
            ->expectsOutput('Telegram support sync: post_timeout_cooldown (waiting after watchdog kill).')
            ->assertExitCode(0);
    }

    public function test_breaker_allowing_leaves_the_gate_open(): void
    {
        $this->plantKill();

        $this->assertFalse(MadelineSyncPhase::cooldownActive());
        $this->assertCount(1, $this->calls());
    }

    public function test_missing_library_is_fail_open(): void
    {
        config(['services.sentinel_breaker.bin' => $this->tmp.'/nope.py']);
        $this->plantKill();
        touch($this->tmp.'/refuse');

        MadelineSyncPhase::armCooldown(600);
        $this->assertTrue(MadelineSyncPhase::cooldownActive(), 'the plain cooldown still works');

        Cache::flush();
        $this->assertFalse(MadelineSyncPhase::cooldownActive(), 'no library = no freeze, cooldown-only');
        $this->assertSame([], $this->calls());
    }

    public function test_alarm_command_delivers_to_the_probe_critical_chat(): void
    {
        config([
            'services.telegram.bot_token' => 'TEST',
            'cabinet_probe.telegram_chat_id' => '111, 222',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('guards:breaker-alarm', ['label' => 'MadelineSync .92', 'body' => 'заморожен'])
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Предохранитель «MadelineSync .92»: заморожен'));
    }

    /**
     * Real-library proof, opt-in: SENTINEL_BREAKER_REAL_BIN=<Uprava>/tools/sentinel_breaker.py.
     * Three kills in an hour, then the 4th start is refused and freeze.json lands.
     */
    public function test_real_library_freezes_after_the_self_kill_budget(): void
    {
        $real = (string) getenv('SENTINEL_BREAKER_REAL_BIN');
        if ($real === '' || ! is_file($real)) {
            $this->markTestSkipped('set SENTINEL_BREAKER_REAL_BIN to run against the real library');
        }
        config([
            'services.sentinel_breaker.python' => (string) (getenv('SENTINEL_BREAKER_REAL_PYTHON') ?: 'python3'),
            'services.sentinel_breaker.bin' => $real,
            'services.sentinel_breaker.alarm_cmd' => '/usr/bin/true',
        ]);
        putenv('SENTINEL_BREAKER_TEST_MODE=1');
        putenv('SENTINEL_BREAKER_NOW=1790000000');
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertFalse(MadelineSyncBreaker::frozen(), "start #{$i} allowed");
                MadelineSyncBreaker::recordKill();
            }
            $this->assertTrue(MadelineSyncBreaker::frozen(), '4th start after 3 kills in an hour is refused');
            $this->assertFileExists($this->tmp.'/state/madeline_sync/freeze.json');
            $freeze = json_decode((string) file_get_contents($this->tmp.'/state/madeline_sync/freeze.json'), true);
            $this->assertSame('self_kill', $freeze['guardian_class']);
        } finally {
            putenv('SENTINEL_BREAKER_TEST_MODE');
            putenv('SENTINEL_BREAKER_NOW');
        }
    }
}
