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
            '/^php artisan optimize$/m',
            $script,
            'deploy.sh must still warm caches via artisan optimize',
        );
        $this->assertMatchesRegularExpression(
            '/chown\s+-R\s+"\$\{APP_USER:-www-data\}:\$\{APP_USER:-www-data\}"\s+\\\s*\n\s+"\$APP_DIR\/storage\/framework\/views"/',
            $script,
            'root-owned compiled Blade 500s php-fpm on touch() — chown views after optimize',
        );

        $optimizePos = strpos($script, "php artisan optimize\n");
        $chownPos = strpos($script, 'storage/framework/views');
        $this->assertNotFalse($optimizePos);
        $this->assertNotFalse($chownPos);
        $this->assertGreaterThan(
            $optimizePos,
            $chownPos,
            'chown of compiled views must run after artisan optimize writes them',
        );
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
