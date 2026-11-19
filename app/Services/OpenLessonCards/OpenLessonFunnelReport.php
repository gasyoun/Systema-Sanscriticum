<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\BotSubscriber;

/**
 * Воронка @samskrte_bot в цифрах для админки: аудитория → нажали
 * «✍️ Участвовать» → подписались на канал (согласие) → заполнили анкету → лид.
 * Каждая ступень — подмножество предыдущей, считается по полям подписчика.
 */
class OpenLessonFunnelReport
{
    /**
     * Ступени воронки; percent — доля от предыдущей ступени (у первой null).
     *
     * @return list<array{key: string, label: string, count: int, percent: ?int}>
     */
    public function steps(): array
    {
        $steps = [
            'audience' => ['Аудитория бота', BotSubscriber::query()],
            'join' => ['Нажали «Участвовать»', BotSubscriber::query()->whereNotNull('join_step')],
            'consent' => ['Подписались на канал', BotSubscriber::query()->whereNotNull('consent_at')],
            'joined' => ['Заполнили анкету', BotSubscriber::query()->whereNotNull('joined_at')],
            'lead' => ['Лид в CRM', BotSubscriber::query()->whereNotNull('lead_id')],
        ];

        $result = [];
        $previous = null;
        foreach ($steps as $key => [$label, $query]) {
            $count = $query->count();
            $result[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'percent' => $previous === null ? null : ($previous > 0 ? (int) round($count * 100 / $previous) : 0),
            ];
            $previous = $count;
        }

        return $result;
    }

    /** @return array{started: int, blocked: int} */
    public function audience(): array
    {
        return [
            'started' => BotSubscriber::query()->where('source', BotSubscriber::SOURCE_START)->count(),
            'blocked' => BotSubscriber::query()->whereNotNull('blocked_at')->count(),
        ];
    }
}
