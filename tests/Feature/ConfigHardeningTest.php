<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Console\Command;
use ReflectionProperty;
use Tests\TestCase;

/**
 * H3311 — session config hardening: безопасный дефолт Secure-куки,
 * CORS allowlist (default deny), дубликат ключа 'yandex' в services.php,
 * параметризованный TrustProxies и прод-предсброс deploy:config-preflight.
 */
class ConfigHardeningTest extends TestCase
{
    /** @test */
    public function session_secure_resolves_true_when_env_is_unset(): void
    {
        $previous = $this->forgetEnv('SESSION_SECURE_COOKIE');

        try {
            $session = require config_path('session.php');

            $this->assertTrue($session['secure']);
        } finally {
            $this->restoreEnv('SESSION_SECURE_COOKIE', $previous);
        }
    }

    /** @test */
    public function session_secure_still_honours_explicit_false_for_local_http_dev(): void
    {
        $previous = $this->forgetEnv('SESSION_SECURE_COOKIE');

        try {
            $_ENV['SESSION_SECURE_COOKIE'] = 'false';
            $_SERVER['SESSION_SECURE_COOKIE'] = 'false';
            putenv('SESSION_SECURE_COOKIE=false');

            $session = require config_path('session.php');

            $this->assertFalse($session['secure']);
        } finally {
            $this->restoreEnv('SESSION_SECURE_COOKIE', $previous);
        }
    }

    /** @test */
    public function cors_middleware_rejects_forged_origin_on_api_paths(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->call(
            'OPTIONS',
            '/api/public/schedule',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://evil.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    /** @test */
    public function cors_allowlisted_origin_receives_allow_origin_header(): void
    {
        config(['cors.allowed_origins' => ['https://samskrte.ru']]);

        $response = $this->call(
            'OPTIONS',
            '/api/public/schedule',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://samskrte.ru',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        $this->assertSame('https://samskrte.ru', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @test */
    public function services_config_has_no_duplicate_top_level_keys(): void
    {
        $keys = $this->topLevelArrayKeys(config_path('services.php'));

        $duplicates = array_keys(array_filter(array_count_values($keys), static fn (int $c): bool => $c > 1));

        $this->assertSame([], $duplicates, 'Duplicate top-level keys in config/services.php: '.implode(', ', $duplicates));
    }

    /** @test */
    public function yandex_speech_and_yandex_socialite_blocks_are_independently_readable(): void
    {
        $keys = $this->topLevelArrayKeys(config_path('services.php'));

        $this->assertContains('yandex_speech', $keys);
        $this->assertContains('yandex', $keys);

        // Регрессионная булавка: speech-ключи больше не протекают в Socialite-блок.
        $this->assertArrayNotHasKey('api_key', config('services.yandex'));
        $this->assertArrayNotHasKey('client_id', config('services.yandex_speech'));
    }

    /** @test */
    public function trusted_proxies_parse_env_list_with_empty_default(): void
    {
        $proxies = new ReflectionProperty(TrustProxies::class, 'proxies');

        config(['security.trusted_proxies' => '']);
        $this->assertSame([], $proxies->getValue(new TrustProxies));

        config(['security.trusted_proxies' => '10.0.0.0/8, 192.168.1.1 ,']);
        $this->assertSame(['10.0.0.0/8', '192.168.1.1'], $proxies->getValue(new TrustProxies));

        config(['security.trusted_proxies' => '*']);
        $this->assertSame(['*'], $proxies->getValue(new TrustProxies));
    }

    /** @test */
    public function preflight_hard_fails_in_production_on_non_true_secure_cookie(): void
    {
        foreach ([false, null, 'false', '0'] as $bad) {
            config(['app.env' => 'production', 'session.secure' => $bad]);

            $this->artisan('deploy:config-preflight')
                ->expectsOutputToContain('SESSION_SECURE_COOKIE must be true')
                ->assertExitCode(Command::FAILURE);
        }
    }

    /** @test */
    public function preflight_passes_in_production_with_secure_cookie_true(): void
    {
        foreach ([true, 1, 'true', '1'] as $good) {
            config(['app.env' => 'production', 'session.secure' => $good]);

            $this->artisan('deploy:config-preflight')->assertExitCode(Command::SUCCESS);
        }
    }

    /** @test */
    public function preflight_does_not_block_local_even_with_insecure_cookie(): void
    {
        config(['app.env' => 'local', 'session.secure' => false]);

        $this->artisan('deploy:config-preflight')->assertExitCode(Command::SUCCESS);
    }

    /** @test */
    public function preflight_warns_but_passes_when_production_trusted_proxies_empty(): void
    {
        config([
            'app.env' => 'production',
            'session.secure' => true,
            'security.trusted_proxies' => '',
        ]);

        $this->artisan('deploy:config-preflight')
            ->expectsOutputToContain('TRUSTED_PROXIES is empty')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * Убрать переменную из всех адаптеров phpdotenv; вернуть прежнее значение.
     */
    private function forgetEnv(string $key): ?string
    {
        $previous = getenv($key);

        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return $previous === false ? null : $previous;
    }

    private function restoreEnv(string $key, ?string $previous): void
    {
        if ($previous === null) {
            $this->forgetEnv($key);

            return;
        }

        $_ENV[$key] = $previous;
        $_SERVER[$key] = $previous;
        putenv($key.'='.$previous);
    }

    /**
     * Топ-уровневые строковые ключи массива `return [...]` из конфиг-файла —
     * статический скан токенов (ловит именно тот класс бага, что H3311:
     * дубликат ключа, который PHP глотает молча).
     *
     * @return list<string>
     */
    private function topLevelArrayKeys(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $depth = 0;
        $keys = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '[' || $token === '(' || $token === '{') {
                    $depth++;
                } elseif ($token === ']' || $token === ')' || $token === '}') {
                    $depth--;
                }

                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || $depth !== 1) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                $keys[] = stripcslashes(substr($token[1], 1, -1));
            }
        }

        return $keys;
    }
}
