<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Activity\ActivityTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для обновления last_activity_at + heartbeat сессии.
 *
 * Ключевое: throttle через Redis — раз в 60 секунд на юзера.
 * Без этого каждый клик/AJAX делал бы UPDATE в БД → 500 юзеров × N запросов/мин = проблема.
 *
 * Работает только для залогиненных. Не писать в БД для гостей.
 */
final class TrackUserActivity
{
    /** Как часто обновляем БД (секунды). Увеличишь — будет экономичнее, но "last_seen" менее точный. */
    private const THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly ActivityTracker $tracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // После того, как ответ сформирован — обрабатываем трекинг.
        // Делаем в конце, чтобы трекинг не замедлял рендер страницы.
        $this->track($request);

        return $response;
    }

    private function track(Request $request): void
{
    if (!Auth::check()) {
        return;
    }

    $user = Auth::user();

    if ($user->is_admin) {
        return;
    }

    if ($request->is('api/heartbeat*')) {
        return;
    }

    $userId = $user->id;
    $throttleKey = "activity:throttled:{$userId}";

    try {
        $acquired = Redis::set($throttleKey, '1', 'EX', self::THROTTLE_SECONDS, 'NX');
        
        if (!$acquired) {
            return;
        }

        $this->updateActivity($user->id, $request);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('TrackUserActivity failed', [
            'user_id' => $userId,
            'error'   => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
    }
}

    /**
     * Обновляем last_activity_at в users и heartbeat в user_sessions.
     * Делаем через DB::table() для скорости — не трогаем Eloquent events.
     */
    private function updateActivity(int $userId, Request $request): void
    {
        $now = now();

        // 1. Обновляем last_activity_at у юзера
        DB::table('users')
            ->where('id', $userId)
            ->update(['last_activity_at' => $now]);

        // 2. Находим/обновляем активную сессию
        $sessionRecord = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('session_id', $request->session()->getId())
            ->first();

        if ($sessionRecord) {
            // Обычный путь: есть активная сессия — тикаем пульс
            DB::table('user_sessions')
                ->where('id', $sessionRecord->id)
                ->update([
                    'last_heartbeat_at' => $now,
                    'pages_viewed'      => $sessionRecord->pages_viewed + 1,
                ]);
        } else {
            // Edge case: юзер залогинен, но сессии нет (например, уже был залогинен
            // когда мы выкатили трекинг — Login event не срабатывал).
            // Создаём сессию "на лету".
            $user = \App\Models\User::find($userId);
            if ($user) {
                $this->tracker->startSession($user, $request);
            }
        }
    }
}