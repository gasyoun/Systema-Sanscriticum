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

            // In-video resume (H1450, W2). Оба необязательны — хосты без API
            // текущей позиции (или флаг video_resume выключен) просто не шлют их,
            // и heartbeat работает ровно как раньше.
            'position' => ['sometimes', 'integer', 'min:0'],
            'duration' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
