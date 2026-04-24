<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

/**
 * Сервис для работы с трекингом активности студентов.
 *
 * Зачем отдельный сервис:
 * - DRY: логика "открыть сессию" нужна и в LoginListener, и в middleware
 *   (на случай если юзер залогинился ДО деплоя трекинга — сессии нет, но надо создать)
 * - Изолируем попадание в БД — легко обернуть в try/catch и не уронить прод
 *   из-за ошибки в трекинге
 */
final class ActivityTracker
{
    /**
     * Открыть новую сессию для пользователя.
     * Если уже есть активная — возвращает её (идемпотентность).
     */
    public function startSession(User $user, Request $request): UserSession
    {
        // Если уже есть активная сессия для этого laravel session_id — переиспользуем
        $laravelSessionId = $request->session()->getId();

        $existing = UserSession::where('session_id', $laravelSessionId)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        return UserSession::create([
            'user_id'           => $user->id,
            'session_id'        => $laravelSessionId,
            'started_at'        => now(),
            'last_heartbeat_at' => now(),
            'ip_address'        => $request->ip(),
            'user_agent'        => mb_substr($request->userAgent() ?? '', 0, 500),
            'device_type'       => $this->detectDeviceType($agent),
            'browser'           => mb_substr((string) $agent->browser(), 0, 50) ?: null,
            'os'                => mb_substr((string) $agent->platform(), 0, 50) ?: null,
            'is_active'         => true,
        ]);
    }

    /**
     * Обработать логин: обновить счётчики + создать сессию + записать событие.
     * Всё в транзакции — либо всё, либо ничего.
     */
    public function handleLogin(User $user, Request $request): ?UserSession
    {
        try {
            return DB::transaction(function () use ($user, $request) {
                // Атомарно инкрементим счётчик и обновляем метаданные
                $user->forceFill([
                    'last_login_at'    => now(),
                    'last_activity_at' => now(),
                    'last_login_ip'    => $request->ip(),
                    'login_count'      => $user->login_count + 1,
                ])->save();

                $session = $this->startSession($user, $request);

                $this->logEvent(
                    user: $user,
                    session: $session,
                    type: ActivityEvent::TYPE_LOGIN,
                    data: [
                        'ip'         => $request->ip(),
                        'user_agent' => mb_substr($request->userAgent() ?? '', 0, 200),
                    ],
                    request: $request,
                );

                return $session;
            });
        } catch (\Throwable $e) {
            // Трекинг НИКОГДА не должен ломать логин. Ловим всё, пишем в лог.
            Log::warning('ActivityTracker::handleLogin failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Обработать logout: закрыть сессию, обновить total_time_spent, записать событие.
     */
    public function handleLogout(User $user, Request $request): void
    {
        try {
            DB::transaction(function () use ($user, $request) {
                $session = UserSession::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->where('session_id', $request->session()->getId())
                    ->first();

                if ($session) {
                    $session->close(now());

                    // Накапливаем суммарное время в профиле юзера
                    $user->forceFill([
                        'total_time_spent' => $user->total_time_spent + $session->duration_seconds,
                    ])->save();
                }

                $this->logEvent(
                    user: $user,
                    session: $session,
                    type: ActivityEvent::TYPE_LOGOUT,
                    data: [
                        'duration_seconds' => $session?->duration_seconds ?? 0,
                    ],
                    request: $request,
                );
            });
        } catch (\Throwable $e) {
            Log::warning('ActivityTracker::handleLogout failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Записать сырое событие в activity_events.
     *
     * ВАЖНО: Не используем Eloquent::create() из-за партиционирования —
     * insert через DB::table() быстрее и не триггерит observers (которых на этой модели быть не должно).
     */
    public function logEvent(
        User $user,
        ?UserSession $session,
        string $type,
        array $data = [],
        ?Request $request = null,
    ): void {
        try {
            DB::table('activity_events')->insert([
                'user_id'    => $user->id,
                'session_id' => $session?->id,
                'event_type' => $type,
                'event_data' => !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                'url'        => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
                'ip_address' => $request?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ActivityTracker::logEvent failed', [
                'user_id' => $user->id,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Определить тип устройства по User-Agent.
     */
    private function detectDeviceType(Agent $agent): string
    {
        if ($agent->isRobot()) {
            return 'bot';
        }
        if ($agent->isTablet()) {
            return 'tablet';
        }
        if ($agent->isMobile()) {
            return 'mobile';
        }
        if ($agent->isDesktop()) {
            return 'desktop';
        }
        return 'unknown';
    }
}