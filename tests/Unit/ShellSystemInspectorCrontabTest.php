<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServerGuards\ShellSystemInspector;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * H1933 false positive: www-data reading root's crontab must not get its own table.
 */
class ShellSystemInspectorCrontabTest extends TestCase
{
    public function test_bare_crontab_l_is_not_used_for_a_different_user(): void
    {
        Process::fake([
            'crontab -u root -l' => Process::result(output: '', exitCode: 1),
            // Without the fix this successful bare listing was returned for root.
            'crontab -l' => Process::result(
                output: "* * * * * /usr/local/sbin/systema-schedule-run.sh\n",
                exitCode: 0,
            ),
            'whoami' => Process::result(output: "www-data\n", exitCode: 0),
        ]);

        $inspector = new ShellSystemInspector;
        $got = $inspector->crontabFor('root');

        // Старый баг: возвращал www-data crontab (строка schedule-run).
        $this->assertNull($got, 'чужой bare crontab -l нельзя выдавать за root');
        $this->assertNotSame(
            "* * * * * /usr/local/sbin/systema-schedule-run.sh\n",
            $got,
        );
    }

    public function test_mirror_file_is_used_when_live_root_crontab_is_unreadable(): void
    {
        Process::fake([
            'crontab -u root -l' => Process::result(output: '', exitCode: 1),
            'crontab -l' => Process::result(output: "should-not-use\n", exitCode: 0),
            'whoami' => Process::result(output: "www-data\n", exitCode: 0),
        ]);

        $dir = storage_path('app/server_guards');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $mirror = $dir.'/crontab-root.installed';
        $body = "*/30 * * * * /usr/local/sbin/systema-auto-deploy-run.sh >> /var/www/html/storage/logs/auto_deploy.log 2>&1\n";
        file_put_contents($mirror, $body);

        try {
            $inspector = new ShellSystemInspector;
            $this->assertSame($body, $inspector->crontabFor('root'));
        } finally {
            @unlink($mirror);
        }
    }

    public function test_own_user_may_use_bare_crontab_l(): void
    {
        // currentUsername() предпочитает posix_getpwuid — fake whoami не подменит.
        $me = 'test-local-user';
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (\is_array($info) && isset($info['name']) && $info['name'] !== '') {
                $me = (string) $info['name'];
            }
        }

        $own = "*/15 * * * * /usr/local/sbin/systema-watchdog-run.sh \"cabinet:probe\" cabinet 120\n";

        Process::fake(function (object $process) use ($me, $own) {
            $cmd = is_array($process->command ?? null)
                ? implode(' ', $process->command)
                : (string) ($process->command ?? '');

            if ($cmd === "crontab -u {$me} -l") {
                return Process::result(output: '', exitCode: 1);
            }
            if ($cmd === 'crontab -l') {
                return Process::result(output: $own, exitCode: 0);
            }
            if ($cmd === 'whoami') {
                return Process::result(output: $me."\n", exitCode: 0);
            }

            return Process::result(output: '', exitCode: 1);
        });

        $inspector = new ShellSystemInspector;
        $this->assertSame($own, $inspector->crontabFor($me));
    }
}
