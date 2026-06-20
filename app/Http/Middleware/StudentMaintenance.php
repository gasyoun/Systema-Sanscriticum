<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MarketingSetting;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Режим техобслуживания студенческого кабинета. Когда включён флаг в
 * MarketingSetting, обычные студенты видят заглушку (503), а админка, редактор
 * лекций, вебхуки и витрина продолжают работать (они вне этой группы роутов).
 *
 * Обход: админы и носители валидной секрет-куки (см. /maintenance-bypass/{token}).
 */
class StudentMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = MarketingSetting::cached();

        if (! $settings?->student_maintenance_enabled) {
            return $next($request);
        }

        if ($this->bypasses($request, $settings)) {
            return $next($request);
        }

        return response()
            ->view('maintenance', ['message' => $settings->student_maintenance_message], 503)
            ->header('Retry-After', '3600');
    }

    private function bypasses(Request $request, MarketingSetting $settings): bool
    {
        $user = $request->user();

        // Админы продолжают пользоваться кабинетом (и видят применённые правки).
        if ($user && ((bool) $user->is_admin
            || in_array($user->role, [Roles::SUPER_ADMIN, Roles::ADMIN], true))) {
            return true;
        }

        // Секрет-кука (для QA-проверки под обычным студентом).
        $secret = (string) $settings->student_maintenance_secret;
        if ($secret !== ''
            && hash_equals($secret, (string) $request->cookie('student_maintenance_bypass'))) {
            return true;
        }

        return false;
    }
}
