<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Канал-независимый расчёт дневных метрик разговора поддержки (H1837, S10).
 *
 * Единственное место, где живёт арифметика строки `support_daily_rollups`.
 * На вход — хронологический список {@see UnifiedMessage} за один день по одному
 * субъекту (TG-чат / веб-тред / пользователь); на выход — готовый набор колонок.
 * Оба агрегатора (TG-сторона и веб-сторона) считают ОДНОЙ этой функцией, иначе
 * «unresolved-after-N-hours» и доля ИИ-ответов означали бы в двух каналах разное,
 * а сводный deflection-отчёт складывал бы несравнимое.
 *
 * Таблицы сообщений при этом по-прежнему НЕ сливаются (см.
 * docs/support-subsystem-map.md): общей является форма чтения, не хранилище.
 */
final class SupportRollupMetrics
{
    /**
     * @param  Collection<int, UnifiedMessage>  $messages  сообщения одного дня, по возрастанию времени
     * @param  int  $unresolvedAfterHours  порог KPI «висит без ответа», часы
     * @param  CarbonInterface  $now  «сейчас» для оценки ещё не истёкшего порога
     * @return array{
     *     first_message_at: CarbonInterface,
     *     last_message_at: CarbonInterface,
     *     incoming_count: int,
     *     outgoing_count: int,
     *     human_reply_count: int,
     *     ai_suggested_count: int,
     *     ai_sent_count: int,
     *     is_unanswered: bool,
     *     first_response_seconds: int|null,
     *     unresolved_after_hours: bool
     * }
     */
    public static function fromMessages(
        Collection $messages,
        int $unresolvedAfterHours,
        CarbonInterface $now,
    ): array {
        $incoming = $messages->filter(fn (UnifiedMessage $message): bool => $message->isIncoming());
        $outgoing = $messages->reject(fn (UnifiedMessage $message): bool => $message->isIncoming());

        $firstInbound = $incoming->first();
        $firstResponse = $firstInbound
            ? $outgoing->first(fn (UnifiedMessage $message): bool => $message->sentAt->greaterThan($firstInbound->sentAt))
            : null;

        return [
            'first_message_at' => $messages->first()->sentAt,
            'last_message_at' => $messages->last()->sentAt,
            'incoming_count' => $incoming->count(),
            'outgoing_count' => $outgoing->count(),
            'human_reply_count' => $messages
                ->filter(fn (UnifiedMessage $message): bool => $message->responderType === UnifiedMessage::RESPONDER_HUMAN)
                ->count(),
            'ai_suggested_count' => $messages
                ->filter(fn (UnifiedMessage $message): bool => $message->aiState === 'suggested')
                ->count(),
            'ai_sent_count' => $messages
                ->filter(fn (UnifiedMessage $message): bool => $message->aiState === 'sent')
                ->count(),
            'is_unanswered' => $incoming->isNotEmpty() && $outgoing->isEmpty(),
            'first_response_seconds' => $firstInbound && $firstResponse
                ? (int) $firstInbound->sentAt->diffInSeconds($firstResponse->sentAt)
                : null,
            'unresolved_after_hours' => self::unresolvedAfterHours($messages, $unresolvedAfterHours, $now),
        ];
    }

    /**
     * KPI «вопрос провисел дольше порога».
     *
     * Считается от ПОСЛЕДНЕГО входящего дня: разговор «решён», если после него
     * пришёл исходящий не позже, чем через N часов. Три исхода:
     *   - ответ пришёл в пределах порога → false;
     *   - ответ пришёл, но позже порога → true (историческая просрочка, задним
     *     числом не «выздоравливает»);
     *   - ответа нет → true только когда порог УЖЕ истёк по $now — свежий вопрос
     *     внутри окна ещё не просрочка, иначе метрика мигала бы каждый день.
     *
     * Флаг — снимок на момент агрегации: пересчёт того же дня обновляет его
     * (для веб-стороны это делает почасовой support:rollup-web).
     *
     * @param  Collection<int, UnifiedMessage>  $messages
     */
    private static function unresolvedAfterHours(
        Collection $messages,
        int $unresolvedAfterHours,
        CarbonInterface $now,
    ): bool {
        if ($unresolvedAfterHours <= 0) {
            return false;
        }

        $lastInbound = $messages->last(fn (UnifiedMessage $message): bool => $message->isIncoming());

        if (! $lastInbound) {
            return false;
        }

        $deadline = $lastInbound->sentAt->copy()->addHours($unresolvedAfterHours);

        $reply = $messages->first(fn (UnifiedMessage $message): bool => ! $message->isIncoming()
            && $message->sentAt->greaterThan($lastInbound->sentAt));

        return $reply
            ? $reply->sentAt->greaterThan($deadline)
            : $now->greaterThan($deadline);
    }
}
