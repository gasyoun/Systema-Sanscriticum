<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\WebSupportRollupAggregator;
use App\Support\SupportRollupMetrics;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;

/**
 * Дневные rollup'ы TG-support-стороны (импорт userbot'а).
 *
 * С H1837 (S10) арифметика метрик вынесена в {@see SupportRollupMetrics} и общая с
 * веб-стороной ({@see WebSupportRollupAggregator}) — так KPI
 * «unresolved-after-N-hours» и доли ИИ/человеческих ответов значат в двух каналах
 * одно и то же. Здесь остаётся ровно то, что действительно телеграм-специфично:
 * выбор чатов дня и признак «первое обращение контакта».
 */
class SupportDailyRollupAggregator
{
    public function __construct(
        private readonly SupportTopicClassifier $topicClassifier,
    ) {}

    public function aggregateDate(string|\DateTimeInterface $date): void
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $unresolvedAfterHours = (int) config('support.rollup.unresolved_after_hours', 24);

        TelegramSupportChat::query()
            ->whereHas('messages', fn ($query) => $query->whereBetween('sent_at', [$start, $end]))
            ->each(function (TelegramSupportChat $chat) use ($day, $start, $end, $unresolvedAfterHours): void {
                $messages = TelegramSupportMessage::query()
                    ->with(['chat', 'contact', 'responder:id,name'])
                    ->where('telegram_support_chat_id', $chat->id)
                    ->whereBetween('sent_at', [$start, $end])
                    ->orderBy('sent_at')
                    ->get();

                if ($messages->isEmpty()) {
                    return;
                }

                $hasNewContact = $messages
                    ->where('direction', 'incoming')
                    ->contains(fn (TelegramSupportMessage $message) => $message->contact
                        && $message->contact->first_inbound_at
                        && $message->contact->first_inbound_at->timezone(config('app.timezone'))->toDateString() === $day->toDateString());

                $metrics = SupportRollupMetrics::fromMessages(
                    $messages->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message)),
                    $unresolvedAfterHours,
                    CarbonImmutable::now(config('app.timezone')),
                );

                $conversation = SupportDailyRollup::updateOrCreate(
                    [
                        'telegram_support_chat_id' => $chat->id,
                        'conversation_date' => $day->startOfDay(),
                    ],
                    $metrics + [
                        'channel' => SupportDailyRollup::CHANNEL_TELEGRAM,
                        'has_new_contact' => $hasNewContact,
                    ],
                );

                $this->topicClassifier->classify($conversation);
            });
    }
}
