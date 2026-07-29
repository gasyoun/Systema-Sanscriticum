<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        // Пакет описывает Telegram-аккаунт поддержки (респонденты и чаты читаются
        // из TelegramSupportMessage), поэтому и метрики скоупим тем же каналом:
        // с H1837 в rollup'ах живут ещё и веб-строки. Кросс-канальные метрики —
        // у `support:topic-ranking`, а не здесь.
        $query = SupportDailyRollup::query()->telegram()->whereDate('conversation_date', $date);

        return [
            'conversations' => (clone $query)->count(),
            'incoming' => (int) (clone $query)->sum('incoming_count'),
            'outgoing' => (int) (clone $query)->sum('outgoing_count'),
            'new_contacts' => (clone $query)->where('has_new_contact', true)->count(),
            'unanswered' => (clone $query)->where('is_unanswered', true)->count(),
            'human_replies' => (int) (clone $query)->sum('human_reply_count'),
            'ai_suggested' => (int) (clone $query)->sum('ai_suggested_count'),
            'ai_sent' => (int) (clone $query)->sum('ai_sent_count'),
        ];
    }

    private function topicsForDate(string $date): array
    {
        return SupportTopicAssignment::query()
            ->select('category', DB::raw('count(*) as total'))
            ->whereHas('conversation', fn ($query) => $query->telegram()->whereDate('conversation_date', $date))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn (SupportTopicAssignment $assignment) => [
                'category' => $assignment->category,
                'total' => (int) $assignment->total,
            ])
            ->all();
    }

    private function respondersForDate(string $date): array
    {
        $start = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
        $end = $start->endOfDay();

        return TelegramSupportMessage::query()
            ->with('responder:id,name')
            ->whereBetween('sent_at', [$start, $end])
            ->where('direction', 'outgoing')
            ->get()
            ->groupBy(fn (TelegramSupportMessage $message) => ($message->responder_type ?? 'unknown').'|'.($message->responder_user_id ?? 'none'))
            ->map(fn ($messages) => [
                'type' => $messages->first()->responder_type ?? 'unknown',
                'label' => $messages->first()->responder?->name
                    ?? $messages->first()->responder_marker
                    ?? ($messages->first()->responder_type ?? 'unknown'),
                'total' => $messages->count(),
            ])
            ->values()
            ->all();
    }

    private function chatsForDate(string $date): array
    {
        return SupportDailyRollup::query()
            ->telegram()
            ->with(['chat.linkedUser:id,name,email', 'topicAssignments'])
            ->whereDate('conversation_date', $date)
            ->orderByDesc('last_message_at')
            ->limit(25)
            ->get()
            ->map(fn (SupportDailyRollup $conversation) => [
                'id' => $conversation->id,
                'chat_id' => $conversation->chat->telegram_chat_id,
                'title' => $conversation->chat->title
                    ?? $conversation->chat->linkedUser?->name
                    ?? $conversation->chat->username
                    ?? 'Telegram chat '.$conversation->chat->telegram_chat_id,
                'topics' => $conversation->topicAssignments->pluck('category')->values()->all(),
                'incoming' => $conversation->incoming_count,
                'outgoing' => $conversation->outgoing_count,
                'new_contact' => $conversation->has_new_contact,
                'unanswered' => $conversation->is_unanswered,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])
            ->all();
    }
}
