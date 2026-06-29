<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportConversation;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;

class SupportConversationAggregator
{
    public function __construct(
        private readonly SupportTopicClassifier $topicClassifier,
    ) {}

    public function aggregateDate(string|\DateTimeInterface $date): void
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        TelegramSupportChat::query()
            ->whereHas('messages', fn ($query) => $query->whereBetween('sent_at', [$start, $end]))
            ->each(function (TelegramSupportChat $chat) use ($day, $start, $end): void {
                $messages = TelegramSupportMessage::query()
                    ->where('telegram_support_chat_id', $chat->id)
                    ->whereBetween('sent_at', [$start, $end])
                    ->orderBy('sent_at')
                    ->get();

                if ($messages->isEmpty()) {
                    return;
                }

                $firstInbound = $messages->firstWhere('direction', 'incoming');
                $firstResponse = $firstInbound
                    ? $messages->first(fn (TelegramSupportMessage $message) => $message->direction === 'outgoing'
                        && $message->sent_at->greaterThan($firstInbound->sent_at))
                    : null;

                $hasNewContact = $messages
                    ->where('direction', 'incoming')
                    ->contains(fn (TelegramSupportMessage $message) => $message->contact
                        && $message->contact->first_inbound_at
                        && $message->contact->first_inbound_at->timezone(config('app.timezone'))->toDateString() === $day->toDateString());

                $conversation = SupportConversation::updateOrCreate(
                    [
                        'telegram_support_chat_id' => $chat->id,
                        'conversation_date' => $day->startOfDay(),
                    ],
                    [
                        'first_message_at' => $messages->first()->sent_at,
                        'last_message_at' => $messages->last()->sent_at,
                        'incoming_count' => $messages->where('direction', 'incoming')->count(),
                        'outgoing_count' => $messages->where('direction', 'outgoing')->count(),
                        'human_reply_count' => $messages->where('responder_type', 'human')->count(),
                        'ai_suggested_count' => $messages->where('ai_state', 'suggested')->count(),
                        'ai_sent_count' => $messages->where('ai_state', 'sent')->count(),
                        'is_unanswered' => $messages->where('direction', 'incoming')->isNotEmpty()
                            && $messages->where('direction', 'outgoing')->isEmpty(),
                        'has_new_contact' => $hasNewContact,
                        'first_response_seconds' => $firstInbound && $firstResponse
                            ? $firstInbound->sent_at->diffInSeconds($firstResponse->sent_at)
                            : null,
                    ],
                );

                $this->topicClassifier->classify($conversation);
            });
    }
}
