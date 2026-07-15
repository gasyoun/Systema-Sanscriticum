<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Services\Activity\CabinetTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Первопартийный приёмник клиентских событий кабинета (H962, Phase 0).
 *
 * POST /dvaram/telemetry { event, data? } — только auth-студент, только
 * события из белого списка ActivityEvent::CLIENT_CABINET_EVENTS, data —
 * маленький плоский массив скаляров (обрезается, не валит запрос).
 * Всегда 204: телеметрия не имеет права ломать UX, клиент шлёт fire-and-forget.
 */
class CabinetTelemetryController extends Controller
{
    /** Максимум ключей контекста и длина строкового значения. */
    private const MAX_DATA_KEYS = 8;

    private const MAX_VALUE_LENGTH = 120;

    public function store(Request $request, CabinetTelemetry $telemetry): JsonResponse
    {
        $event = (string) $request->input('event', '');

        if (in_array($event, ActivityEvent::CLIENT_CABINET_EVENTS, true)) {
            $telemetry->emit(
                user: $request->user(),
                event: $event,
                data: $this->sanitizedData($request),
                request: $request,
            );
        }
        // Неизвестное имя события молча игнорируем (не 422): старые вкладки
        // после деплоя нового списка не должны сыпать ошибки в консоль.

        return response()->json(null, 204);
    }

    private function sanitizedData(Request $request): array
    {
        $raw = $request->input('data');
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach (array_slice($raw, 0, self::MAX_DATA_KEYS, true) as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            $clean[mb_substr($key, 0, 40)] = is_string($value)
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH)
                : $value;
        }

        return $clean;
    }
}
