<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Services\TelegramSupport\SupportTopicClassifier;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;

/**
 * H1837 — веб-близнец [`SupportDailyRollupAggregator`](../TelegramSupport/SupportDailyRollupAggregator.php).
 *
 * Считает ту же дневную свёртку, но поверх `chat_messages`, сгруппированных
 * тредом `SupportConversation`. Направление/тип отправителя НЕ выводим здесь
 * заново: единственный источник правды на веб-стороне — role-маппинг
 * [`UnifiedMessage::fromChatMessage()`](../../Support/UnifiedMessage.php),
 * поэтому свёртка идёт через него. Разъехавшись, две копии маппинга дали бы
 * две «правды» о том, что считается ответом куратора.
 *
 * Триггер другой, чем у Telegram: там свёртка вызывается из синка по
 * затронутым датам, здесь сообщения приходят живьём — свёртку гоняет
 * ежедневная команда `support:rollup-web` (и она же догоняет прошлые дни).
 *
 * Сообщения без треда (`support_conversation_id IS NULL` — исторические строки
 * до H536) не сворачиваются: у них нет ключа группировки, а домысливать тред
 * по `user_id` задним числом значило бы придумывать данные.
 */
class WebChatDailyRollupAggregator
{
    public function __construct(
        private readonly SupportTopicClassifier $topicClassifier,
    ) {}

    /** @return int Сколько строк rollup'а создано/обновлено за дату. */
    public function aggregateDate(string|\DateTimeInterface $date): int
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $aggregated = 0;

        SupportConversation::query()
            ->whereHas('chatMessages', fn ($query) => $query->whereBetween('created_at', [$start, $end]))
            ->each(function (SupportConversation $conversation) use ($day, $start, $end, &$aggregated): void {
                $messages = ChatMessage::query()
                    ->with('answeredBy:id,name')
                    ->where('support_conversation_id', $conversation->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message));

                if ($messages->isEmpty()) {
                    return;
                }

                $incoming = $messages->filter(fn (UnifiedMessage $m) => $m->isIncoming());
                $outgoing = $messages->reject(fn (UnifiedMessage $m) => $m->isIncoming());

                $firstInbound = $incoming->first();
                $firstResponse = $firstInbound
                    ? $outgoing->first(fn (UnifiedMessage $m) => $m->sentAt->greaterThan($firstInbound->sentAt))
                    : null;

                $rollup = SupportDailyRollup::updateOrCreate(
                    [
                        'support_conversation_id' => $conversation->id,
                        'conversation_date' => $day->startOfDay(),
                    ],
                    [
                        'channel' => SupportDailyRollup::CHANNEL_WEB,
                        'first_message_at' => $messages->first()->sentAt,
                        'last_message_at' => $messages->last()->sentAt,
                        'incoming_count' => $incoming->count(),
                        'outgoing_count' => $outgoing->count(),
                        'human_reply_count' => $messages->filter(fn (UnifiedMessage $m) => $m->isHumanReply())->count(),
                        'ai_suggested_count' => $messages->filter(fn (UnifiedMessage $m) => $m->aiState === 'suggested')->count(),
                        'ai_sent_count' => $messages->filter(fn (UnifiedMessage $m) => $m->aiState === 'sent')->count(),
                        'is_unanswered' => $incoming->isNotEmpty() && $outgoing->isEmpty(),
                        // Веб-близнец «нового контакта»: тред заведён в этот же
                        // день. У гостя нет строки в contacts, считать первый
                        // входящий по треду — прямой аналог first_inbound_at.
                        'has_new_contact' => $conversation->created_at !== null
                            && $conversation->created_at->timezone(config('app.timezone'))->toDateString() === $day->toDateString(),
                        'first_response_seconds' => $firstInbound && $firstResponse
                            ? (int) $firstInbound->sentAt->diffInSeconds($firstResponse->sentAt)
                            : null,
                    ],
                );

                $this->topicClassifier->classify($rollup);
                $aggregated++;
            });

        return $aggregated;
    }
}
