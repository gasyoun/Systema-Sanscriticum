<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * H2480 — Wave 4 deploy-surface: deploy tooling must not echo secret values.
 *
 * These are file-shape assertions against origin-tracked scripts, not a live
 * `docker compose config` run (that would need a host .env).
 */
final class DeploySurfaceSecretsTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function readRepoFile(string $relative): string
    {
        $path = $this->repoRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertFileExists($path, $relative.' must exist for the deploy-surface gate');
        $body = file_get_contents($path);
        $this->assertNotFalse($body, $relative.' must be readable');

        return $body;
    }

    public function test_deploy_sh_chowns_compiled_views_after_optimize(): void
    {
        $script = $this->readRepoFile('deploy.sh');

        $this->assertMatchesRegularExpression(
            '/^run_as_app_user php artisan optimize$/m',
            $script,
            'deploy.sh must still warm caches via artisan optimize — as the FPM user (H4848)',
        );
        $this->assertMatchesRegularExpression(
            '/chown\s+-R\s+"\$\{APP_USER:-www-data\}:\$\{APP_USER:-www-data\}"\s+\\\s*\n\s+"\$APP_DIR\/storage\/framework\/views"/',
            $script,
            'root-owned compiled Blade 500s php-fpm on touch() — chown views after optimize',
        );

        $optimizePos = strpos($script, "php artisan optimize\n");
        $probePos = strpos($script, 'php artisan cabinet:probe --fail-on-critical --no-alert');
        $this->assertNotFalse($optimizePos);
        $this->assertNotFalse($probePos);
        $this->assertSame(
            3,
            preg_match_all('/^chown_compiled_views$/m', $script),
            'helper call after optimize, cabinet:probe, and guards:verify',
        );
        $afterOptimize = strpos($script, 'chown_compiled_views', $optimizePos);
        $afterProbe = strpos($script, 'chown_compiled_views', $probePos);
        $failPos = strpos($script, 'cabinet:probe: critical после деплоя');
        $this->assertNotFalse($afterOptimize);
        $this->assertNotFalse($afterProbe);
        $this->assertNotFalse($failPos);
        $this->assertGreaterThan($optimizePos, $afterOptimize);
        $this->assertGreaterThan($probePos, $afterProbe);
        $this->assertGreaterThan(
            $afterProbe,
            $failPos,
            'chown_compiled_views must run before fail() when probe is critical (H3194: fail is exit 1)',
        );
    }

    /**
     * H4848 — the 14-09-2026 window class, closed by construction.
     *
     * deploy.sh warmed caches as root, so Blade landed `root:755`; php-fpm
     * (www-data) then could not `touch($compiledPath, $lastModified + 1)` in
     * BladeCompiler.php:215, and Filament `/admin` 500'd for the whole window
     * between the root compile and `chown_compiled_views` (17-08, 20-08, 09-09,
     * 14-09-2026 — the 14-09 run took 2 m 46 s). Warming as the FPM user means
     * root never creates the file at all, so there is no window to narrow.
     */
    public function test_deploy_sh_compiles_views_as_fpm_user_not_root(): void
    {
        $script = $this->readRepoFile('deploy.sh');

        $this->assertMatchesRegularExpression(
            '/^run_as_app_user php artisan optimize$/m',
            $script,
            'H4848: caches must be warmed as the FPM user',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^php artisan optimize$/m',
            $script,
            'H4848: a bare root `artisan optimize` re-opens the /admin 500 window',
        );
        $this->assertMatchesRegularExpression(
            '/^run_as_app_user php artisan cabinet:probe --fail-on-critical --no-alert$/m',
            $script,
            'H4848: the probe must run as www-data — as root it stayed green while /admin 500d (14-09)',
        );
        $this->assertMatchesRegularExpression(
            '/^run_as_app_user php artisan filament:optimize/m',
            $script,
            'H4848: filament:optimize may compile Blade — run it as the FPM user too',
        );

        // The guard the mission asked for: a root-owned compiled view fails the deploy.
        $this->assertMatchesRegularExpression(
            '/^assert_no_root_views\(\) \{$/m',
            $script,
            'H4848: deploy.sh must define the root-owned-compiled-views guard',
        );
        $this->assertSame(
            1,
            preg_match_all('/^assert_no_root_views$/m', $script),
            'H4848: the guard must be called exactly once',
        );

        $lastChown = strrpos($script, "\nchown_compiled_views\n");
        $guardCall = strpos($script, "\nassert_no_root_views\n");
        $this->assertNotFalse($lastChown);
        $this->assertNotFalse($guardCall);
        $this->assertGreaterThan(
            $lastChown,
            $guardCall,
            'H4848: the guard runs after the last fix-up (H3194: fail is exit 1)',
        );

        // Belt-and-braces: even a stray root write stays group-writable for www-data.
        $this->assertMatchesRegularExpression('/^umask 002$/m', $script);
        $this->assertStringContainsString('chmod 2770', $script);
    }

    public function test_deploy_sh_does_not_dump_env_or_secret_variables(): void
    {
        $script = $this->readRepoFile('deploy.sh');

        $this->assertStringNotContainsString('printenv', $script);
        $this->assertDoesNotMatchRegularExpression('/^\s*set\s+-x\b/m', $script);
        $this->assertDoesNotMatchRegularExpression('/\bcat\s+[\'"]?\.env\b/', $script);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:echo|printf)\b[^\n]*\$(?:\{)?(?:DB_PASSWORD|APP_KEY|AWS_SECRET_ACCESS_KEY|[A-Z0-9_]+(?:SECRET|TOKEN|PASSWORD))\b/',
            $script,
        );
    }

    public function test_compose_healthcheck_does_not_interpolate_host_db_password(): void
    {
        $yml = $this->readRepoFile('docker-compose.yml');
        $this->assertSame(1, preg_match('/healthcheck:\s*\n((?:[ \t]+.+\n)+)/', $yml, $m));
        $block = $m[1];
        $testLines = [];
        foreach (preg_split('/\R/', $block) as $line) {
            if (preg_match('/^\s*(?:-\s*)?test:/', $line) === 1 || preg_match('/^\s+-\s+/', $line) === 1) {
                $testLines[] = $line;
            }
        }
        $this->assertNotSame([], $testLines, 'healthcheck must declare a test command');
        $joined = implode("\n", $testLines);

        $this->assertDoesNotMatchRegularExpression(
            '/\$\{(?:DB_PASSWORD|MYSQL_[A-Z_]*PASSWORD)[^}]*\}/',
            $joined,
            'healthcheck test must not compose-interpolate the host DB password (docker compose config would print it)',
        );
        $this->assertStringContainsString('$$MYSQL_ROOT_PASSWORD', $joined);
    }

    public function test_ci_deploy_workflow_does_not_echo_ssh_key(): void
    {
        $wf = $this->readRepoFile('.github/workflows/deploy.yml');

        $this->assertDoesNotMatchRegularExpression(
            '/\becho\b[^\n]*secrets\.DEPLOY_SSH_KEY/',
            $wf,
        );
        $this->assertMatchesRegularExpression(
            '/printf[^\n]*secrets\.DEPLOY_SSH_KEY[^\n]*>\s*~\/\.ssh\/deploy_key/',
            $wf,
        );
    }

    public function test_gitignore_excludes_dotenv(): void
    {
        $gi = $this->readRepoFile('.gitignore');

        $this->assertMatchesRegularExpression('/^\.env$/m', $gi);
        $this->assertMatchesRegularExpression('/^\.env\.production$/m', $gi);
    }

    public function test_env_example_does_not_ship_secret_values(): void
    {
        $example = $this->readRepoFile('.env.example');

        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_SECRET_ACCESS_KEY=\s*$/m', $example);
    }
}
