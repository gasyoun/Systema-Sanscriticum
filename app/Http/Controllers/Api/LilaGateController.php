<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * H4396 — серверная половина ворот бесплатных тренажёров /lila.
 *
 * Дыра (census PAYWALL_CENSUS_2026-09-08): бюджет «5 бесплатных раундов на
 * семейство» жил ТОЛЬКО в localStorage (public/lila/gate.js) — очистка
 * localStorage сбрасывала счёт. Теперь бюджет живёт на сервере, в
 * game_events (event=round, по одному на завершённый раунд), а ключ
 * счётчика — идентификатор, производный ОТ СЕССИИ (hash от session id,
 * без PII — контракт R20 не расширяется): очистка localStorage больше не
 * сбрасывает бюджет, для сброса нужна новая сессия (как сегодня — смена
 * устройства; ворота остаются funnel-наддувом, не DRM).
 *
 * Приватность (R20): ни IP, ни user-agent — только производный от сессии
 * anon_id в существующей колонке game_events.anon_id.
 *
 * Ноль изъятия бесплатных возможностей: бюджет остаётся 5 на семейство,
 * залогиненные студенты по-прежнему никогда не запираются (gate.js
 * возвращается до ворот), при недоступности сервера gate.js откатывается
 * к прежнему localStorage-поведению (голые статические хосты работают).
 */
class LilaGateController extends Controller
{
    /** Бесплатные раунды на семейство — тот же бюджет, что у gate.js (H1678). */
    public const FREE_PLAYS_PER_FAMILY = 5;

    public function budget(Request $request): JsonResponse
    {
        if ($request->user() !== null) {
            return response()->json(['authenticated' => true]);
        }

        $family = $this->family($request);

        if ($family === null) {
            return response()->json(['error' => 'unknown family'], 422);
        }

        return response()->json([
            'authenticated' => false,
            'anon_id' => $this->gateAnonId($request),
            'family' => $family,
            'used' => $this->used($request, $family),
            'free' => self::FREE_PLAYS_PER_FAMILY,
        ]);
    }

    /**
     * Один завершённый раунд. Пишет append-only game_events (сбой записи
     * проглатывается — телеметрия не имеет права ломать тренажёр) и возвращает
     * свежий бюджет: gate.js показывает стену по ответу сервера.
     */
    public function round(Request $request): JsonResponse
    {
        if ($request->user() !== null) {
            return response()->json(['authenticated' => true]);
        }

        $family = $this->family($request);

        if ($family === null) {
            return response()->json(['error' => 'unknown family'], 422);
        }

        $anonId = $this->gateAnonId($request);

        try {
            GameEvent::create([
                'anon_id' => $anonId,
                'drill' => $family,
                'band' => null,
                'event' => GameEvent::ROUND,
                'payload' => null,
                // Ворота применяются только к анонимам; флаг читает сервер.
                'authenticated' => false,
                'user_id' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Lila gate round write failed', [
                'family' => $family,
                'error' => $e->getMessage(),
            ]);
        }

        $used = $this->used($request, $family);

        return response()->json([
            'authenticated' => false,
            'anon_id' => $anonId,
            'family' => $family,
            'used' => $used,
            'free' => self::FREE_PLAYS_PER_FAMILY,
            'gated' => $used >= self::FREE_PLAYS_PER_FAMILY,
        ]);
    }

    /** Семейство тренажёров: первый сегмент под /lila/ (как в gate.js). */
    private function family(Request $request): ?string
    {
        $raw = (string) $request->input('family', '');
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $raw) ?? '';

        return ($clean !== '' && mb_strlen($clean) <= 40) ? $clean : null;
    }

    /**
     * Ключ бюджета: производный от web-сессии, НЕ от клиентского payload.
     * Очистка localStorage идентификатор не меняет; новая сессия — новый ключ
     * (семантика «на устройство», как и раньше). Не PII: sha256 от session id.
     */
    private function gateAnonId(Request $request): string
    {
        return substr(hash('sha256', 'lila-gate:'.$request->session()->getId()), 0, 32);
    }

    private function used(Request $request, string $family): int
    {
        return GameEvent::query()
            ->where('event', GameEvent::ROUND)
            ->where('drill', $family)
            ->where('anon_id', $this->gateAnonId($request))
            ->count();
    }
}
