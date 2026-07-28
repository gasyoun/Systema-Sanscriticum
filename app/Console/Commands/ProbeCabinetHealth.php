<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 * Пульс кабинета (H1777): login smoke-менеджера + GET ключевых поверхностей.
 *
 * Не внешний curl (hairpin DNS на VPS ломал H1735): in-process Request через
 * HTTP-kernel после Auth::attempt — проверяет Auth, middleware, Blade/Filament
 * рендер и 500/Whoops, а не «порт 443 отвечает».
 *
 * Fail-open: без TEST_MANAGER_PASSWORD — no-op SUCCESS; healthchecks/Telegram
 * ошибки не роняют команду. Тревоги: CABINET_PROBE_PING_URL + Telegram
 * (CABINET_PROBE_TELEGRAM_CHAT_ID, default ADMIN_TELEGRAM_ID) с cooldown.
 *
 *   php artisan cabinet:probe
 *   php artisan cabinet:probe --dry
 */
class ProbeCabinetHealth extends Command
{
    private const CACHE_WAS_DOWN = 'cabinet_probe:was_down';

    private const CACHE_LAST_ALERT_AT = 'cabinet_probe:last_tg_alert_at';

    protected $signature = 'cabinet:probe
        {--dry : Прогнать проверки, не слать healthchecks/Telegram}
        {--force-alert : Игнорировать TG-cooldown (для ручной проверки доставки)}';

    protected $description = 'Пульс кабинета: login smoke-менеджера + /dvaram/messages/calendar/open-lessons/admin (+ hybrid if on)';

    public function handle(): int
    {
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $email = User::normalizeEmail((string) config('services.test_manager.email', ''));
        $password = (string) config('services.test_manager.password', '');

        if ($password === '' || $email === '') {
            $this->comment('TEST_MANAGER_EMAIL/PASSWORD пусты — cabinet:probe выключен (no-op).');

            return self::SUCCESS;
        }

        $failures = [];

        try {
            $failures = array_merge($failures, $this->probeAuth($email, $password));
            if ($failures === []) {
                $failures = array_merge($failures, $this->probeSurfaces());
            }
        } catch (Throwable $e) {
            $failures[] = 'probe crashed: '.$e->getMessage();
            Log::error('cabinet:probe crashed', ['error' => $e->getMessage()]);
        } finally {
            Auth::logout();
        }

        $healthy = $failures === [];

        if ($healthy) {
            $surfaceCount = count(config('cabinet_probe.surfaces', []));
            if (config('features.cabinet_hybrid')) {
                $surfaceCount += count(config('cabinet_probe.hybrid_surfaces', []));
            }
            $this->info('Кабинет жив: login + '.$surfaceCount.' поверхностей OK.');

        } else {
            $this->warn('Кабинет болен:');
            foreach ($failures as $failure) {
                $this->line('  • '.$failure);
            }
            Log::error('cabinet:probe failed', ['failures' => $failures]);
        }

        $this->reportToHealthchecks($healthy, $failures);
        $this->reportToTelegram($healthy, $failures);

        // Как heartbeat:ping — SUCCESS всегда, чтобы слот schedule:run не
        // валился из-за сторожа. Сигнал — /fail, TG или лог.
        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function probeAuth(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return ["smoke-user $email не найден — users:ensure-test-manager?"];
        }

        if ($user->role !== Roles::MANAGER) {
            return ["smoke-user $email role={$user->role}, ожидали manager"];
        }

        if (! Hash::check($password, $user->password)) {
            return ['пароль smoke-user не сходится с TEST_MANAGER_PASSWORD'];
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            return ['Auth::attempt отказал при верном hash — guard/сломанный Auth'];
        }

        $authed = Auth::user();
        if (! $authed instanceof User || $authed->id !== $user->id) {
            return ['после Auth::attempt auth()->user() пуст или чужой'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function probeSurfaces(): array
    {
        $failures = [];
        $markers = config('cabinet_probe.error_markers', []);
        $surfaces = config('cabinet_probe.surfaces', []);
        // Hybrid chassis pages 404 while the flag is OFF — only probe them when live.
        if (config('features.cabinet_hybrid')) {
            $surfaces = array_merge($surfaces, config('cabinet_probe.hybrid_surfaces', []));
        }

        foreach ($surfaces as $surface) {
            $label = (string) ($surface['label'] ?? $surface['name'] ?? $surface['uri'] ?? '?');
            $uri = $this->resolveUri($surface);
            if ($uri === null) {
                $failures[] = "$label: маршрут не зарегистрирован";

                continue;
            }

            try {
                $response = $this->getAuthenticated($uri);
            } catch (Throwable $e) {
                $failures[] = "$label: exception ".$e->getMessage();

                continue;
            }

            $status = $response->getStatusCode();
            // 2xx и мягкий 302/303 (Filament иногда редиректит внутри панели)
            // — ок; 4xx/5xx — нет. 419 = CSRF/сессия; 403 = canAccessPanel.
            if ($status >= 400) {
                $failures[] = "$label: HTTP $status на $uri";

                continue;
            }

            $body = (string) $response->getContent();
            foreach ($markers as $marker) {
                if ($marker !== '' && str_contains($body, (string) $marker)) {
                    $failures[] = "$label: в теле найден маркер «{$marker}»";
                    break;
                }
            }

            // Filament: менеджер обязан canAccessPanel; если ушли на login —
            // панель закрыта (права/guard).
            if (! empty($surface['panel'])) {
                $final = $response->headers->get('Location') ?? '';
                if ($status === 302 && str_contains($final, 'login')) {
                    $failures[] = "$label: редирект на login (canAccessPanel/guard)";
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

    private function getAuthenticated(string $uri): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new \RuntimeException('нет auth user перед probe surface');
        }

        // SessionGuard держит $this->user после login; middleware Authenticate
        // читает guard, а request->user() — resolver. Задаём оба, чтобы
        // Blade auth()->user() и $request->user() не разъехались.
        Auth::guard('web')->setUser($user);

        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn () => Auth::guard('web')->user());

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        // handle() может сбросить guard через logout middleware — вернём.
        Auth::guard('web')->setUser($user);

        return $response;
    }

    /**
     * @param  list<string>  $failures
     */
    private function reportToHealthchecks(bool $healthy, array $failures): void
    {
        $url = trim((string) config('cabinet_probe.ping_url', ''));
        if ($url === '') {
            $this->comment('CABINET_PROBE_PING_URL пуст — внешний пинг не отправлен (лог/консоль есть).');

            return;
        }

        $target = $healthy ? $url : rtrim($url, '/').'/fail';
        $body = $healthy ? 'ok' : implode('; ', $failures);

        if ($this->option('dry')) {
            $this->comment('--dry: пинг не отправлен. Ушёл бы на '.$target);

            return;
        }

        try {
            $response = Http::timeout((int) config('cabinet_probe.timeout', 15))
                ->withBody($body, 'text/plain')
                ->post($target);

            if ($response->successful()) {
                $this->info('Пинг: '.$target);
            } else {
                $this->error('healthchecks ответил '.$response->status());
                Log::warning('cabinet:probe healthchecks non-2xx', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            $this->error('Пинг не ушёл: '.$e->getMessage());
            Log::warning('cabinet:probe healthchecks unreachable', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Telegram-алерт через основной бот (TELEGRAM_BOT_TOKEN).
     * Sync HTTP — не через очередь: при падении Horizon кабинетный алерт
     * всё равно должен уйти (очередь могла бы молчать вместе с ним).
     *
     * @param  list<string>  $failures
     */
    private function reportToTelegram(bool $healthy, array $failures): void
    {
        $chatIds = $this->telegramChatIds();
        if ($chatIds === []) {
            $this->comment('CABINET_PROBE_TELEGRAM_CHAT_ID / ADMIN_TELEGRAM_ID пусты — TG-алерт выключен.');

            return;
        }

        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            $this->comment('TELEGRAM_BOT_TOKEN пуст — TG-алерт невозможен.');

            return;
        }

        $wasDown = (bool) Cache::get(self::CACHE_WAS_DOWN, false);
        $cooldown = max(1, (int) config('cabinet_probe.telegram_cooldown_minutes', 60));
        $force = (bool) $this->option('force-alert');

        if ($healthy) {
            Cache::forget(self::CACHE_WAS_DOWN);
            Cache::forget(self::CACHE_LAST_ALERT_AT);
            if (! $wasDown) {
                return; // всё и так было нормально — молчим
            }
            $text = "✅ <b>Личный кабинет снова работает</b>\n"
                ."\n"
                .$this->telegramUrlBlock()
                ."\n"
                ."Проверка прошла: вход smoke-менеджера и ключевые страницы снова отвечают.\n"
                .'Поднять сервер может только Артём (@t3t3r1n) — отвечает нечасто, поднимает небыстро.';
        } else {
            $lastAt = Cache::get(self::CACHE_LAST_ALERT_AT);
            if (! $force && $wasDown && $lastAt !== null) {
                $elapsed = now()->diffInMinutes($lastAt, absolute: true);
                if ($elapsed < $cooldown) {
                    $this->comment('TG-cooldown: следующий алерт через ~'.($cooldown - (int) $elapsed).' мин.');

                    return;
                }
            }
            $lines = array_map(
                static fn (string $f): string => '• '.e($f),
                array_slice($failures, 0, 8),
            );
            $text = "🚨 <b>Личный кабинет не работает</b>\n"
                ."\n"
                .$this->telegramUrlBlock()
                ."\n"
                ."Что упало:\n"
                .implode("\n", $lines)
                ."\n\n"
                ."Поднять сервер может только Артём (@t3t3r1n) — отвечает нечасто, поднимает небыстро.\n"
                .'На сервере: <code>php artisan cabinet:probe</code>';
        }

        if ($this->option('dry')) {
            $this->comment('--dry: TG не отправлен → '.implode(',', $chatIds));

            return;
        }

        $anySent = false;
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
                    $anySent = true;
                    $this->info('TG-алерт → chat '.$chatId);
                } else {
                    $this->error('TG API chat '.$chatId.': '.$response->body());
                    Log::warning('cabinet:probe telegram send failed', [
                        'chat_id' => $chatId,
                        'body' => $response->body(),
                    ]);
                }
            } catch (Throwable $e) {
                $this->error('TG не ушёл ('.$chatId.'): '.$e->getMessage());
                Log::warning('cabinet:probe telegram unreachable', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $healthy && $anySent) {
            Cache::put(self::CACHE_WAS_DOWN, true, now()->addDays(2));
            Cache::put(self::CACHE_LAST_ALERT_AT, now(), now()->addDays(2));
        }
        if ($healthy && $wasDown && $anySent) {
            // recovery already cleared cache above
        }
        if (! $healthy && $anySent === false) {
            // still mark down so recovery can fire once delivery works
            Cache::put(self::CACHE_WAS_DOWN, true, now()->addDays(2));
        }
    }

    /**
     * @return list<string>
     */
    private function telegramChatIds(): array
    {
        $raw = config('cabinet_probe.telegram_chat_id', '');
        if ($raw === null || $raw === false) {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim((string) $raw)) ?: [];

        return array_values(array_filter(array_map('strval', $parts), static fn (string $id): bool => $id !== ''));
    }

    /** Общий блок ссылок для TG-алертов (полные URL). */
    private function telegramUrlBlock(): string
    {
        return "Сам сайт: https://samskrte.ru\n"
            ."Вход: https://samskrte.ru/login\n"
            ."Кабинет: https://samskrte.ru/dvaram\n"
            ."Админка: https://samskrte.ru/admin\n"
            ."Витрина: https://samskrte.ru/online\n";
    }
}

