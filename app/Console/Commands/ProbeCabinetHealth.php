<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CabinetProbeRun;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Пульс кабинета (H1777 + H1794): public URLs + manager + optional student,
 * history, TG (critical/soft), healthchecks, runbook.
 *
 * Deferred: Playwright, auto-restart, public status page.
 */
class ProbeCabinetHealth extends Command
{
    private const CACHE_WAS_DOWN = 'cabinet_probe:was_down';

    private const CACHE_LAST_ALERT_AT = 'cabinet_probe:last_tg_alert_at';

    protected $signature = 'cabinet:probe
        {--dry : Прогнать проверки, не слать healthchecks/Telegram и не писать history}
        {--force-alert : Игнорировать TG-cooldown}';

    protected $description = 'Пульс кабинета: public + manager (+ student) surfaces, history, TG';

    public function handle(): int
    {
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $started = hrtime(true);
        /** @var list<array{message: string, severity: string}> $failures */
        $failures = [];

        try {
            $failures = array_merge($failures, $this->probeSurfacesList(
                config('cabinet_probe.public_surfaces', []),
                authenticated: false,
            ));

            $mgrEmail = User::normalizeEmail((string) config('services.test_manager.email', ''));
            $mgrPass = (string) config('services.test_manager.password', '');

            if ($mgrPass === '' || $mgrEmail === '') {
                $this->comment('TEST_MANAGER_* пусты — manager-ветка пропущена.');
            } else {
                $failures = array_merge($failures, $this->probeAuth(
                    $mgrEmail,
                    $mgrPass,
                    expectedRole: Roles::MANAGER,
                    label: 'manager',
                ));
                if (! $this->hasCritical($failures)) {
                    $surfaces = config('cabinet_probe.surfaces', []);
                    if (config('features.cabinet_hybrid')) {
                        $surfaces = array_merge($surfaces, config('cabinet_probe.hybrid_surfaces', []));
                    }
                    $failures = array_merge($failures, $this->probeSurfacesList($surfaces, authenticated: true));
                }
                Auth::logout();
            }

            $stuEmail = User::normalizeEmail((string) config('services.test_student.email', ''));
            $stuPass = (string) config('services.test_student.password', '');
            if ($stuPass !== '' && $stuEmail !== '') {
                $failures = array_merge($failures, $this->probeAuth(
                    $stuEmail,
                    $stuPass,
                    expectedRole: null,
                    label: 'student',
                ));
                if (! $this->hasAuthFailure($failures, 'student')) {
                    $failures = array_merge($failures, $this->probeSurfacesList(
                        config('cabinet_probe.student_surfaces', []),
                        authenticated: true,
                    ));
                }
                Auth::logout();
            } else {
                $this->comment('TEST_STUDENT_* пусты — student-ветка пропущена.');
            }
        } catch (Throwable $e) {
            $failures[] = ['message' => 'probe crashed: '.$e->getMessage(), 'severity' => 'critical'];
            Log::error('cabinet:probe crashed', ['error' => $e->getMessage()]);
        } finally {
            Auth::logout();
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $criticalFails = array_values(array_filter($failures, fn ($f) => ($f['severity'] ?? 'critical') === 'critical'));
        $softFails = array_values(array_filter($failures, fn ($f) => ($f['severity'] ?? '') === 'soft'));
        $healthy = $failures === [];
        $criticalHealthy = $criticalFails === [];

        if ($healthy) {
            $this->info('Кабинет жив: все проверки OK ('.$durationMs.' ms).');
        } else {
            $this->warn('Кабинет болен ('.$durationMs.' ms):');
            foreach ($failures as $f) {
                $this->line('  • ['.($f['severity'] ?? '?').'] '.$f['message']);
            }
            Log::error('cabinet:probe failed', ['failures' => $failures]);
        }

        if (! $this->option('dry')) {
            $this->recordHistory($criticalHealthy && $softFails === [], $criticalHealthy, $durationMs, $failures);
        }

        $this->reportToHealthchecks($criticalHealthy, array_column($criticalFails, 'message'));
        $this->reportToTelegram($criticalHealthy, $criticalFails, $softFails);

        return self::SUCCESS;
    }

    /**
     * @return list<array{message: string, severity: string}>
     */
    private function probeAuth(string $email, string $password, ?string $expectedRole, string $label): array
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return [['message' => "$label: user $email не найден — users:ensure-test-{$label}?", 'severity' => 'critical']];
        }

        if ($expectedRole === Roles::MANAGER) {
            if ($user->role !== Roles::MANAGER) {
                return [['message' => "$label: role={$user->role}, ожидали manager", 'severity' => 'critical']];
            }
        } else {
            // Student: not staff roles.
            if (in_array($user->role, [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT, Roles::TEACHER], true)) {
                return [['message' => "$label: role={$user->role} — не студент", 'severity' => 'critical']];
            }
        }

        if (! Hash::check($password, $user->password)) {
            return [['message' => "$label: пароль не сходится с .env", 'severity' => 'critical']];
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            return [['message' => "$label: Auth::attempt отказал", 'severity' => 'critical']];
        }

        if (Auth::id() !== $user->id) {
            return [['message' => "$label: после attempt auth user чужой", 'severity' => 'critical']];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $surfaces
     * @return list<array{message: string, severity: string}>
     */
    private function probeSurfacesList(array $surfaces, bool $authenticated): array
    {
        $failures = [];
        $markers = config('cabinet_probe.error_markers', []);

        foreach ($surfaces as $surface) {
            $label = (string) ($surface['label'] ?? $surface['name'] ?? $surface['uri'] ?? '?');
            $severity = (string) ($surface['severity'] ?? 'critical');
            $uri = $this->resolveUri($surface);
            if ($uri === null) {
                $failures[] = ['message' => "$label: маршрут не зарегистрирован", 'severity' => $severity];

                continue;
            }

            try {
                $response = $authenticated
                    ? $this->getAuthenticated($uri)
                    : $this->getGuest($uri);
            } catch (Throwable $e) {
                $failures[] = ['message' => "$label: exception ".$e->getMessage(), 'severity' => $severity];

                continue;
            }

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $failures[] = ['message' => "$label: HTTP $status на $uri", 'severity' => $severity];

                continue;
            }

            $body = (string) $response->getContent();
            foreach ($markers as $marker) {
                if ($marker !== '' && str_contains($body, (string) $marker)) {
                    $failures[] = ['message' => "$label: маркер «{$marker}»", 'severity' => $severity];
                    break;
                }
            }

            if (! empty($surface['panel'])) {
                $final = $response->headers->get('Location') ?? '';
                if ($status === 302 && str_contains($final, 'login')) {
                    $failures[] = ['message' => "$label: редирект на login", 'severity' => $severity];
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $surface
     */
    private function resolveUri(array $surface): ?string
    {
        if (! empty($surface['name'])) {
            try {
                return route((string) $surface['name'], [], false);
            } catch (Throwable) {
                return null;
            }
        }

        $uri = (string) ($surface['uri'] ?? '');

        return $uri !== '' ? $uri : null;
    }

    private function getGuest(string $uri): Response
    {
        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function getAuthenticated(string $uri): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new \RuntimeException('нет auth user перед probe surface');
        }

        Auth::guard('web')->setUser($user);

        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn () => Auth::guard('web')->user());

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        Auth::guard('web')->setUser($user);

        return $response;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function hasCritical(array $failures): bool
    {
        foreach ($failures as $f) {
            if (($f['severity'] ?? 'critical') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function hasAuthFailure(array $failures, string $label): bool
    {
        foreach ($failures as $f) {
            if (str_starts_with($f['message'], $label.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function recordHistory(bool $healthy, bool $criticalHealthy, int $durationMs, array $failures): void
    {
        try {
            $messages = array_map(fn ($f) => '['.($f['severity'] ?? '?').'] '.$f['message'], $failures);
            CabinetProbeRun::query()->create([
                'ran_at' => now(),
                'healthy' => $healthy,
                'critical' => ! $criticalHealthy,
                'duration_ms' => $durationMs,
                'failure_count' => count($failures),
                'failures' => $messages === [] ? null : $messages,
                'summary' => $healthy
                    ? 'ok'
                    : mb_substr(implode('; ', $messages), 0, 500),
            ]);

            $keep = max(50, (int) config('cabinet_probe.history_keep', 500));
            $cutoffId = CabinetProbeRun::query()->orderByDesc('id')->skip($keep)->value('id');
            if ($cutoffId) {
                CabinetProbeRun::query()->where('id', '<=', $cutoffId)->delete();
            }
        } catch (Throwable $e) {
            // Migration not applied yet — don't kill the probe.
            Log::warning('cabinet:probe history write failed', ['error' => $e->getMessage()]);
            $this->comment('history: не записано ('.$e->getMessage().')');
        }
    }

    /**
     * @param  list<string>  $failureMessages
     */
    private function reportToHealthchecks(bool $healthy, array $failureMessages): void
    {
        $url = trim((string) config('cabinet_probe.ping_url', ''));
        if ($url === '') {
            $this->comment('CABINET_PROBE_PING_URL пуст — healthchecks не пингуется (см. DEPLOY_QUEUE H1794).');

            return;
        }

        $target = $healthy ? $url : rtrim($url, '/').'/fail';
        $body = $healthy ? 'ok' : implode('; ', $failureMessages);

        if ($this->option('dry')) {
            $this->comment('--dry: healthchecks → '.$target);

            return;
        }

        try {
            $response = Http::timeout((int) config('cabinet_probe.timeout', 15))
                ->withBody($body, 'text/plain')
                ->post($target);

            if ($response->successful()) {
                $this->info('healthchecks: '.$target);
            } else {
                Log::warning('cabinet:probe healthchecks non-2xx', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('cabinet:probe healthchecks unreachable', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<array{message: string, severity: string}>  $criticalFails
     * @param  list<array{message: string, severity: string}>  $softFails
     */
    private function reportToTelegram(bool $criticalHealthy, array $criticalFails, array $softFails): void
    {
        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            $this->comment('TELEGRAM_BOT_TOKEN пуст — TG выключен.');

            return;
        }

        $criticalIds = $this->parseChatIds(config('cabinet_probe.telegram_chat_id', ''));
        $softIds = $this->parseChatIds(config('cabinet_probe.telegram_soft_chat_id', ''));
        if ($softIds === []) {
            $softIds = $criticalIds;
        }

        $wasDown = (bool) Cache::get(self::CACHE_WAS_DOWN, false);
        $cooldown = max(1, (int) config('cabinet_probe.telegram_cooldown_minutes', 60));
        $force = (bool) $this->option('force-alert');

        // Soft-only: alert soft chats without flipping critical downtime.
        if ($criticalHealthy && $softFails !== []) {
            $lines = array_map(fn ($f) => '• '.e($f['message']), array_slice($softFails, 0, 8));
            $text = "⚠️ <b>Кабинет: soft-сбой</b> (hybrid/опциональные страницы)\n\n"
                .$this->telegramUrlBlock()."\n"
                .implode("\n", $lines)."\n\n"
                .$this->telegramRunbook();
            $this->sendTelegram($token, $softIds, $text);

            return;
        }

        if ($criticalHealthy) {
            Cache::forget(self::CACHE_WAS_DOWN);
            Cache::forget(self::CACHE_LAST_ALERT_AT);
            if (! $wasDown) {
                return;
            }
            $text = "✅ <b>Личный кабинет снова работает</b>\n\n"
                .$this->telegramUrlBlock()."\n"
                ."Проверка прошла: вход smoke-аккаунтов и ключевые страницы снова отвечают.\n"
                ."Поднять сервер может только Артём (@t3t3r1n) — отвечает нечасто, поднимает небыстро.\n\n"
                .$this->telegramRunbook();
            $this->sendTelegram($token, $criticalIds, $text);

            return;
        }

        $lastAt = Cache::get(self::CACHE_LAST_ALERT_AT);
        if (! $force && $wasDown && $lastAt !== null) {
            $elapsed = now()->diffInMinutes($lastAt, absolute: true);
            if ($elapsed < $cooldown) {
                $this->comment('TG-cooldown: ~'.($cooldown - (int) $elapsed).' мин.');

                return;
            }
        }

        $lines = array_map(fn ($f) => '• '.e($f['message']), array_slice($criticalFails, 0, 8));
        if ($softFails !== []) {
            $lines[] = '— soft —';
            foreach (array_slice($softFails, 0, 4) as $f) {
                $lines[] = '• '.e($f['message']);
            }
        }

        $text = "🚨 <b>Личный кабинет не работает</b>\n\n"
            .$this->telegramUrlBlock()."\n"
            ."Что упало:\n".implode("\n", $lines)."\n\n"
            ."Поднять сервер может только Артём (@t3t3r1n) — отвечает нечасто, поднимает небыстро.\n\n"
            .$this->telegramRunbook();

        $sent = $this->sendTelegram($token, $criticalIds, $text);
        Cache::put(self::CACHE_WAS_DOWN, true, now()->addDays(2));
        if ($sent) {
            Cache::put(self::CACHE_LAST_ALERT_AT, now(), now()->addDays(2));
        }
    }

    /**
     * @param  list<string>  $chatIds
     */
    private function sendTelegram(string $token, array $chatIds, string $text): bool
    {
        if ($chatIds === []) {
            $this->comment('TG chat_id пуст.');

            return false;
        }

        if ($this->option('dry')) {
            $this->comment('--dry: TG → '.implode(',', $chatIds));

            return false;
        }

        $any = false;
        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout((int) config('cabinet_probe.timeout', 15))
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if ($response->successful() && ($response->json('ok') ?? false)) {
                    $any = true;
                    $this->info('TG → '.$chatId);
                } else {
                    Log::warning('cabinet:probe tg fail', ['chat_id' => $chatId, 'body' => $response->body()]);
                }
            } catch (Throwable $e) {
                Log::warning('cabinet:probe tg error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }

        return $any;
    }

    /**
     * @return list<string>
     */
    private function parseChatIds(mixed $raw): array
    {
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim((string) $raw)) ?: [];

        return array_values(array_filter(array_map('strval', $parts), static fn (string $id): bool => $id !== ''));
    }

    private function telegramUrlBlock(): string
    {
        return "Сам сайт: https://samskrte.ru\n"
            ."Вход: https://samskrte.ru/login\n"
            ."Кабинет: https://samskrte.ru/dvaram\n"
            ."Админка: https://samskrte.ru/admin\n"
            ."Витрина: https://samskrte.ru/online\n";
    }

    private function telegramRunbook(): string
    {
        $steps = config('cabinet_probe.runbook', []);
        if (! is_array($steps) || $steps === []) {
            return '<code>php artisan cabinet:probe</code>';
        }
        $lines = ['<b>Runbook</b>:'];
        foreach ($steps as $step) {
            $lines[] = '• <code>'.e((string) $step).'</code>';
        }

        return implode("\n", $lines);
    }
}
