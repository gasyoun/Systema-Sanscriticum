<?php

namespace App\Services\TelegramSupport;

use App\Models\ChatMessage;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\TelegramSupportMessage;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Пакет чисел для дашборда поддержки.
 *
 * С H1837 (S10) он ДВУХКАНАЛЬНЫЙ: верхние суммы — по обоим каналам, разбивка
 * `by_channel` рядом. Ровно это и есть «единый отчёт без ручной сверки»:
 * deflection по вебу и по TG считается одним кодом из одной таблицы. Пока
 * features.support_web_rollups=false веб-строк нет и все числа совпадают с
 * доH1837-поведением до последней цифры.
 */
class SupportDashboardPacketBuilder
{
    public function build(?string $date = null): array
    {
        $day = CarbonImmutable::parse($date ?? now(), config('app.timezone'));
        $yesterday = $day->subDay();

        return [
            'packet' => 'dashboard.live',
            'kind' => 'telegram_support.analytics',
            'generated_at' => now()->toIso8601String(),
            'date' => $day->toDateString(),
            'timezone' => config('app.timezone'),
            'summary' => [
                'today' => $this->metricsForDate($day->toDateString()),
                'yesterday' => $this->metricsForDate($yesterday->toDateString()),
            ],
            'topics' => $this->topicsForDate($day->toDateString()),
            'responders' => $this->respondersForDate($day->toDateString()),
            'chats' => $this->chatsForDate($day->toDateString()),
        ];
    }

    private function metricsForDate(string $date): array
    {
        $rollups = SupportDailyRollup::query()
            ->whereDate('conversation_date', $date)
            ->get([
                'channel', 'incoming_count', 'outgoing_count', 'human_reply_count',
                'ai_suggested_count', 'ai_sent_count', 'is_unanswered',
                'unresolved_after_hours', 'has_new_contact',
            ]);

        return $this->foldMetrics($rollups) + [
            'by_channel' => $rollups
                ->groupBy('channel')
                ->map(fn ($group) => $this->foldMetrics($group))
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, SupportDailyRollup>  $rollups
     */
    private function foldMetrics($rollups): array
    {
        return [
            'conversations' => $rollups->count(),
            'incoming' => (int) $rollups->sum('incoming_count'),
            'outgoing' => (int) $rollups->sum('outgoing_count'),
            'new_contacts' => $rollups->where('has_new_contact', true)->count(),
            'unanswered' => $rollups->where('is_unanswered', true)->count(),
            'unresolved_after_hours' => $rollups->where('unresolved_after_hours', true)->count(),
            'human_replies' => (int) $rollups->sum('human_reply_count'),
            'ai_suggested' => (int) $rollups->sum('ai_suggested_count'),
            'ai_sent' => (int) $rollups->sum('ai_sent_count'),
        ];
    }

    private function topicsForDate(string $date): array
    {
        return SupportTopicAssignment::query()
            ->select('category', DB::raw('count(*) as total'))
            ->whereHas('conversation', fn ($query) => $query->whereDate('conversation_date', $date))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn (SupportTopicAssignment $assignment) => [
                'category' => $assignment->category,
                'total' => (int) $assignment->total,
            ])
            ->all();
    }

    /**
     * Кто отвечал за день — по ОБОИМ хранилищам через общий read-слой
     * {@see UnifiedMessage}; канал входит в ключ группировки, чтобы один и тот же
     * куратор в вебе и в TG не сливался в одну строку.
     */
    private function respondersForDate(string $date): array
    {
        $start = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
        $end = $start->endOfDay();

        $telegram = TelegramSupportMessage::query()
            ->with(['chat', 'contact', 'responder:id,name'])
            ->whereBetween('sent_at', [$start, $end])
            ->where('direction', 'outgoing')
            ->get()
            ->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message));

        $web = ChatMessage::query()
            ->with('answeredBy:id,name')
            ->whereBetween('created_at', [$start, $end])
            ->where('role', '!=', 'user')
            ->get()
            ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message));

        return $telegram->concat($web)
            ->groupBy(fn (UnifiedMessage $message) => $message->channel
                .'|'.($message->responderType ?? 'unknown')
                .'|'.($message->responderUserId ?? 'none'))
            ->map(fn ($messages) => [
                'channel' => $messages->first()->channel,
                'channel_label' => $messages->first()->channelLabel(),
                'type' => $messages->first()->responderType ?? 'unknown',
                'label' => $messages->first()->responderName
                    ?? $messages->first()->responderMarker
                    ?? $messages->first()->responderType
                    ?? 'unknown',
                'total' => $messages->count(),
            ])
            ->values()
            ->all();
    }

    private function chatsForDate(string $date): array
    {
        return SupportDailyRollup::query()
            ->with([
                'chat.linkedUser:id,name,email',
                'conversation:id,user_id,guest_name,guest_token',
                'conversation.user:id,name',
                'webUser:id,name',
                'topicAssignments',
            ])
            ->whereDate('conversation_date', $date)
            ->orderByDesc('last_message_at')
            ->limit(25)
            ->get()
            ->map(fn (SupportDailyRollup $conversation) => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'channel_label' => $conversation->channelLabel(),
                'chat_id' => $conversation->chat?->telegram_chat_id,
                'title' => $conversation->subjectLabel(),
                'topics' => $conversation->topicAssignments->pluck('category')->values()->all(),
                'incoming' => $conversation->incoming_count,
                'outgoing' => $conversation->outgoing_count,
                'new_contact' => $conversation->has_new_contact,
                'unanswered' => $conversation->is_unanswered,
                'unresolved_after_hours' => $conversation->unresolved_after_hours,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])
            ->all();
    }
}
