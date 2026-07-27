<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Первопартийный приёмник событий воронки бесплатных тренажёров /lila (H1360).
 *
 * POST /api/games/event { anon_id?, drill, band?, event } — публичный (web-guard,
 * потому что флаг authenticated берётся из браузерной сессии, а не с клиента),
 * с throttle. Живёт рядом с probe /api/games/auth (routes/web.php).
 *
 * Приватность (R20): в БД не попадают ни IP, ни user-agent — только короткий
 * anon_id, очищенный до [A-Za-z0-9]{0,32}, так что PII туда физически не пройдёт.
 *
 * Неизвестное имя события -> 422 (в отличие от кабинетной телеметрии, где старые
 * вкладки шлют устаревшие имена; здесь клиент наш и список закрыт). Любой сбой
 * ЗАПИСИ проглатывается (телеметрия не имеет права ломать страницу тренажёра),
 * как CabinetTelemetry::emit.
 */
class GameTelemetryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $event = (string) $request->input('event', '');

        if (! in_array($event, GameEvent::EVENTS, true)) {
            return response()->json(['error' => 'unknown event'], 422);
        }

        try {
            GameEvent::create([
                'anon_id' => $this->anonId($request),
                'drill' => $this->slug($request->input('drill'), 40) ?? 'unknown',
                'band' => $this->slug($request->input('band'), 40),
                'event' => $event,
                // Сервер — единственный источник правды по залогиненности:
                // клиент не может это подделать.
                'authenticated' => $request->user() !== null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GameTelemetry write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        // fire-and-forget: клиент шлёт sendBeacon и не ждёт тела.
        return response()->json(null, 204);
    }

    /** anon_id -> только [A-Za-z0-9], максимум 32 символа; иначе null. Отсекает любую PII. */
    private function anonId(Request $request): ?string
    {
        $raw = (string) $request->input('anon_id', '');
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, 32);
    }

    /** Короткий слаг (drill/band): строка, обрезанная до $max; пустое/не-скаляр -> null. */
    private function slug(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $clean = trim((string) $value);

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
