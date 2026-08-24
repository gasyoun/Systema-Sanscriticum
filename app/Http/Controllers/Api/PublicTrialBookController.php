<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\Crm\TrialBookingService;
use App\Support\TrialBookToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Публичная запись на бесплатное пробное занятие из виджета (H3248, cluster 2).
 *
 * Требуются ОБА флага (`crm_trial_widget_public` + `crm_trial_booking`,
 * ARCHITECTURE §7): выключен любой — эндпоинт отдаёт 404. Токен — HMAC
 * из {@see TrialBookToken}, числовых id в запросе нет. Ответ — только
 * `{ok:true}`: ни ссылки Zoom, ни внутренних id наружу не уходит
 * (граница закреплена тестами PublicScheduleFeedTest/PublicScheduleBookTest).
 */
class PublicTrialBookController extends Controller
{
    public function __invoke(Request $request, TrialBookingService $booking): JsonResponse
    {
        if (! config('features.crm_trial_widget_public') || ! config('features.crm_trial_booking')) {
            abort(404);
        }

        $data = $request->validate([
            'book_token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $scheduleId = TrialBookToken::resolve($data['book_token']);
        if ($scheduleId === null) {
            throw ValidationException::withMessages([
                'book_token' => 'Ссылка записи недействительна.',
            ]);
        }

        /** @var Schedule|null $schedule */
        $schedule = Schedule::query()->find($scheduleId);
        if ($schedule === null) {
            throw ValidationException::withMessages([
                'book_token' => 'Это занятие больше недоступно для записи.',
            ]);
        }

        $deal = $booking->bookFree($data['email'], $schedule, [
            'name' => $data['name'] ?? null,
        ]);

        if ($deal === null) {
            return response()->json(['ok' => false], 422);
        }

        return response()->json(['ok' => true]);
    }
}
