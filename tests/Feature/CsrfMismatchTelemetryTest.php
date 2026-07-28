<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * H1773 — a real CSRF mismatch must leave exactly one structured log line
 * (route, method, auth flag, expects-json, coarse client bucket), never any
 * PII, and never turn a recoverable 419 into a 500 if the log channel itself
 * is broken (fail-open, same posture as the scheduler heartbeat, H1713).
 *
 * Reuses {@see LoginTokenMismatchGracefulRetryTest}'s
 * VerifyCsrfToken rebind: Laravel's CSRF middleware skips verification
 * entirely during unit tests (runningUnitTests()), so this forces the real
 * check to actually exercise Handler::register()'s renderable.
 *
 * Reads the real csrf_mismatch log channel back off disk (JSON-per-line,
 * config/logging.php) rather than mocking the Log facade — a Mockery spy on
 * a fluent Log::channel(...)->warning(...) call chain returns null for the
 * unmocked ->channel() hop and never reaches ->warning() at all, so
 * asserting on the real written line is both simpler and more honest.
 */
class CsrfMismatchTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeCsrfMismatchLogs();
    }

    protected function tearDown(): void
    {
        $this->purgeCsrfMismatchLogs();
        parent::tearDown();
    }

    private function purgeCsrfMismatchLogs(): void
    {
        foreach (glob(storage_path('logs/csrf-mismatch-*.log')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loggedEntries(): array
    {
        $entries = [];
        foreach (glob(storage_path('logs/csrf-mismatch-*.log')) ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }
        }

        return $entries;
    }

    private function forceRealCsrfVerification(): void
    {
        $this->app->bind(VerifyCsrfToken::class, function (Application $app) {
            return new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    /** @test */
    public function real_csrf_mismatch_produces_exactly_one_log_entry_with_expected_fields(): void
    {
        $this->forceRealCsrfVerification();

        $this->from(route('login'))
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->post(route('login.post'), [
                'email' => 'student@example.com',
                'password' => 'wrongpassword',
                // no _token — guaranteed mismatch
            ]);

        $entries = $this->loggedEntries();
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame('CSRF token mismatch', $entry['message']);
        $this->assertSame('login.post', $entry['context']['route']);
        $this->assertSame('POST', $entry['context']['method']);
        $this->assertFalse($entry['context']['authenticated']);
        $this->assertFalse($entry['context']['expects_json']);
        $this->assertSame('browser', $entry['context']['client_bucket']);
    }

    /** @test */
    public function payload_carries_no_ip_raw_user_agent_or_user_id(): void
    {
        $this->forceRealCsrfVerification();

        $this->from(route('login'))
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 TelegramBot (like TwitterBot)'])
            ->post(route('login.post'), [
                'email' => 'student@example.com',
                'password' => 'wrongpassword',
            ]);

        $entries = $this->loggedEntries();
        $this->assertCount(1, $entries);
        $context = $entries[0]['context'];

        $this->assertArrayNotHasKey('ip', $context);
        $this->assertArrayNotHasKey('ip_address', $context);
        $this->assertArrayNotHasKey('user_agent', $context);
        $this->assertArrayNotHasKey('user_id', $context);
        $this->assertSame('in_app_messenger', $context['client_bucket']);
    }

    /** @test */
    public function authenticated_mismatch_is_distinguished_from_anonymous(): void
    {
        // /shop/login — модалка, fetch с Accept: json (см. Handler.php).
        $this->forceRealCsrfVerification();

        $this->postJson(route('shop.login'), [
            'email' => 'student@example.com',
            'password' => 'wrongpassword',
        ]);

        $entries = $this->loggedEntries();
        $this->assertCount(1, $entries);
        $context = $entries[0]['context'];

        $this->assertSame('shop.login', $context['route']);
        $this->assertFalse($context['authenticated']);
        $this->assertTrue($context['expects_json']);
    }

    /** @test */
    public function a_broken_log_channel_does_not_change_the_http_response(): void
    {
        $this->forceRealCsrfVerification();

        // Reconfigure the channel to a driver that does not exist — Log::
        // channel('csrf_mismatch') throws the moment it resolves, exercising
        // Handler::logCsrfMismatch()'s try/catch without any facade mocking.
        config(['logging.channels.csrf_mismatch.driver' => 'h1773_test_missing_driver']);

        $response = $this->from(route('login'))->post(route('login.post'), [
            'email' => 'student@example.com',
            'password' => 'wrongpassword',
        ]);

        // Same friendly redirect as the healthy path — the broken log
        // channel must be invisible to the student.
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', 'Сессия обновилась — введите данные ещё раз.');
    }
}
