<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * Fail-open: без TEST_MANAGER_PASSWORD — no-op SUCCESS; healthchecks-ошибка
 * не роняет команду. Тревога — CABINET_PROBE_PING_URL + /fail при поломке.
 *
 *   php artisan cabinet:probe
 *   php artisan cabinet:probe --dry
 */
class ProbeCabinetHealth extends Command
{
    protected $signature = 'cabinet:probe
        {--dry : Прогнать проверки, не слать пинг на healthchecks}';

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

        // Как heartbeat:ping — SUCCESS всегда, чтобы слот schedule:run не
        // валился из-за сторожа. Сигнал — /fail или лог.
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

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
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
}
