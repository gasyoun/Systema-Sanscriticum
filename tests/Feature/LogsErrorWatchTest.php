<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4648: лог-сторож 500-класса `logs:error-watch`.
 *
 * Инцидент 10-13.09.2026: фатал «CI green, прод 500» — 43 ошибки / 9 студентов /
 * ~3 дня — лежал в daily-логе, и никто его не читал. Сторож сканирует сегодня +
 * вчера (до cutoff UTC), группирует production.ERROR по классу+месту и шлёт
 * TG soft при всплеске ≥3/ч (порог/канал — config/logs_watch.php). Пустой
 * канал — ГРОМКИЙ warn, не тихий пропуск (класс слепого пятна H3797).
 */
class LogsErrorWatchTest extends TestCase
{
    use RefreshDatabase;

    private string $logDir;

    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir().'/logs_watch_test_'.uniqid('', true);
        mkdir($this->logDir, 0775, true);
        $this->statePath = sys_get_temp_dir().'/logs_watch_state_test_'.uniqid('', true).'.json';

        // «Сегодня» = 13-09-2026 15:00 Moscow (12:00 UTC).
        Carbon::setTestNow(Carbon::create(2026, 9, 13, 15, 0, 0, 'Europe/Moscow'));

        config()->set('logs_watch.log_dir', $this->logDir);
        config()->set('logs_watch.state_path', $this->statePath);
        config()->set('logs_watch.telegram_chat_id', '100500');
        config()->set('logs_watch.reminder_hours', 24);
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);
        @unlink($this->statePath);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Строка production.ERROR в формате daily-лога Laravel.
     */
    private function errorLine(string $ts, string $class, string $placeFile, int $placeLine, string $env = 'production'): string
    {
        $msg = 'Boom: '.$class.' exploded';

        return sprintf(
            "[%s] %s.ERROR: %s {\"exception\":\"[object] (%s(code:0): %s at %s:%d) [stacktrace]\"}\n",
            $ts,
            $env,
            $msg,
            str_replace('\\', '\\\\', $class),
            $msg,
            $placeFile,
            $placeLine,
        );
    }

    private function todayPath(): string
    {
        return $this->logDir.'/laravel-2026-09-13.log';
    }

    private function yesterdayPath(): string
    {
        return $this->logDir.'/laravel-2026-09-12.log';
    }

    public function test_burst_above_threshold_sends_telegram_and_writes_state(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:44:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));

        $code = Artisan::call('logs:error-watch');
        $this->assertSame(0, $code);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'всплеск ошибок')
                && str_contains((string) $request['text'], 'RuntimeException')
                && str_contains((string) $request['text'], 'StudentController');
        });
        $this->assertFileExists($this->statePath, 'алерт уходит → state ставится');
    }

    public function test_below_threshold_is_quiet_and_clears_state(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));
        file_put_contents($this->statePath, '{"fingerprint":"old","last_alert_at":"2026-09-13T10:00:00+03:00"}');

        $code = Artisan::call('logs:error-watch');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertStringContainsString('Логи чисты', $out);
        $this->assertFileDoesNotExist($this->statePath, 'зелёный прогон гасит sticky-состояние');
    }

    public function test_different_classes_do_not_sum_into_a_burst(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:07:00', 'LogicException', '/var/www/html/app/Services/Pay.php', 12),
            $this->errorLine('2026-09-13 14:08:00', 'LogicException', '/var/www/html/app/Services/Pay.php', 12),
        ]));

        $code = Artisan::call('logs:error-watch');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertStringContainsString('Логи чисты', $out);
    }

    public function test_non_production_env_and_other_levels_are_ignored(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349, env: 'local'),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349, env: 'local'),
            $this->errorLine('2026-09-13 14:07:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349, env: 'local'),
        ]));

        $code = Artisan::call('logs:error-watch');

        $this->assertSame(0, $code);
        Http::assertNothingSent();
    }

    public function test_empty_channel_warns_loudly_and_does_not_arm_state(): void
    {
        config()->set('logs_watch.telegram_chat_id', '');
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:44:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));

        $code = Artisan::call('logs:error-watch');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        // ГРОМКИЙ пропуск (H3797-класс): многострочный warn-блок, не серая строка.
        $this->assertStringContainsString('⚠️⚠️⚠️', $out);
        $this->assertStringContainsString('LOGS_WATCH_TELEGRAM_CHAT_ID ПУСТ', $out);
        $this->assertStringContainsString('RuntimeException', $out, 'находки показаны в выводе');
        $this->assertFileDoesNotExist($this->statePath, 'алерт не считается отправленным');
    }

    public function test_same_fingerprint_is_sticky_until_green(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:44:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));

        Artisan::call('logs:error-watch');
        Artisan::call('logs:error-watch');

        Http::assertSentCount(1); // тот же класс в окне reminder не пересылается (H2335)

        // Зелёный прогон гасит состояние; новый всплеск после него алертит снова.
        file_put_contents($this->todayPath(), '');
        Artisan::call('logs:error-watch');
        $this->assertFileDoesNotExist($this->statePath);

        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:44:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));
        Artisan::call('logs:error-watch');

        Http::assertSentCount(2); // после зелёного — sticky снят, новый всплеск виден
    }

    public function test_yesterday_log_counts_only_until_utc_cutoff(): void
    {
        // 05:00 UTC = 08:00 Moscow — ДО cutoff 06:00 UTC: считается.
        // 07:00 UTC = 10:00 Moscow — ПОСЛЕ cutoff: не считается.
        file_put_contents($this->yesterdayPath(), implode('', [
            $this->errorLine('2026-09-12 08:00:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-12 08:00:20', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-12 08:00:30', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-12 10:00:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-12 10:00:20', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));

        $code = Artisan::call('logs:error-watch');

        $this->assertSame(0, $code);
        Http::assertSentCount(1); // вчерашний ночной всплеск до 06:00 UTC виден
    }

    public function test_excluded_message_pattern_does_not_count_toward_a_burst(): void
    {
        // H4879 (15-09-2026): known-chronic WARNING/ERROR-class noise (TG
        // harvest roster "peer not present in the internal peer database")
        // must not trip the burst threshold even if it surfaces at ERROR
        // level — second line of defense alongside the harvest-side fix.
        config()->set('logs_watch.excluded_message_patterns', ['not present in the internal peer database']);

        $line = static fn (string $ts): string => sprintf(
            "[%s] production.ERROR: Telegram harvest roster: getPwrChat failed {\"error\":\"This peer is not present in the internal peer database\"}\n",
            $ts,
        );

        file_put_contents($this->todayPath(), implode('', [
            $line('2026-09-13 14:05:33'),
            $line('2026-09-13 14:06:10'),
            $line('2026-09-13 14:06:40'),
        ]));

        $code = Artisan::call('logs:error-watch');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertStringContainsString('Логи чисты', $out);
    }

    public function test_missing_log_files_warn_loudly(): void
    {
        $code = Artisan::call('logs:error-watch');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('⚠️', $out);
        $this->assertStringContainsString('слепой', $out);
        Http::assertNothingSent();
    }

    public function test_dry_run_neither_sends_nor_writes_state(): void
    {
        file_put_contents($this->todayPath(), implode('', [
            $this->errorLine('2026-09-13 14:05:33', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:06:10', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
            $this->errorLine('2026-09-13 14:44:00', 'RuntimeException', '/var/www/html/app/Http/Controllers/StudentController.php', 349),
        ]));

        $code = Artisan::call('logs:error-watch', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->statePath);
        $this->assertStringContainsString('RuntimeException', $out, 'находки печатаются');
    }
}
