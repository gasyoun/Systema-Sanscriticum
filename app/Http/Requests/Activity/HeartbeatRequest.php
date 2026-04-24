<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

final class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],

            // Сколько секунд прошло с предыдущего heartbeat на клиенте.
            // Ограничиваем сверху 90 секундами — клиент шлёт раз в 30 сек,
            // значит 90 это уже явная аномалия (или накопление после sleep).
            // Всё что больше — считаем злоупотреблением и отсекаем.
            'delta_seconds' => ['required', 'integer', 'min:1', 'max:90'],

            // Источник heartbeat — для аналитики. Нужен чтобы отличать
            // обычный тик от "beacon" при закрытии вкладки.
            'source' => ['sometimes', 'string', 'in:tick,beacon'],
        ];
    }
}