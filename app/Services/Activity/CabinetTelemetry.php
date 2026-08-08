<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Телеметрия кабинета (H962, Phase 0 ремейка): тонкая обёртка над
 * ActivityTracker::logEvent для событий спеки §4.
 *
 * Зачем отдельный класс, а не вызовы logEvent по месту:
 * - резолв активной UserSession по laravel session id в одном месте
 *   (контроллеры не должны знать про механику сессий трекинга);
 * - единый try/catch: телеметрия НИКОГДА не ломает страницу/платёж
 *   (тот же контракт, что у ActivityTracker);
 * - PII-конвенция: пишем внутренний user_id в первопартийную БД, как и
 *   весь существующий activity-трекинг; НИКАКИХ сторонних трекеров (R20).
 */
final class CabinetTelemetry
{
    public function __construct(
        private readonly ActivityTracker $tracker,
        private readonly FunnelTelemetry $funnel,
    ) {}

    /**
     * Записать событие §4 в activity_events.
     * $data — маленький плоский массив контекста (kind/tab/course_id...).
     */
    public function emit(User $user, string $event, array $data = [], ?Request $request = null): void
    {
        try {
            $this->tracker->logEvent(
                user: $user,
                session: $this->activeSession($user, $request),
                type: $event,
                data: $data,
                request: $request,
            );

            // H2378: first meaningful cabinet surface → once-per-user funnel step.
            if ($event === ActivityEvent::CABINET_HOME_VIEW) {
                $this->funnel->emitFirstCabinetAction($user, $event, $request);
            }
        } catch (\Throwable $e) {
            Log::warning('CabinetTelemetry::emit failed', [
                'user_id' => $user->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function activeSession(User $user, ?Request $request): ?UserSession
    {
        if (! $request || ! $request->hasSession()) {
            return null;
        }

        return UserSession::where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->where('is_active', true)
            ->first();
    }
}
