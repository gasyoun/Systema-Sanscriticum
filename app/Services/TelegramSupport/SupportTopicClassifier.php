<?php

namespace App\Services\TelegramSupport;

use App\Models\ChatMessage;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportMessage;

class SupportTopicClassifier
{
    public function classify(SupportDailyRollup $conversation): void
    {
        $conversation->topicAssignments()->where('source', 'keyword')->delete();

        $text = $this->dayText($conversation);

        $matched = false;

        SupportTopicRule::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('category')
            ->get()
            ->each(function (SupportTopicRule $rule) use ($conversation, $text, &$matched): void {
                foreach ($this->keywordsOf($rule) as $keyword) {
                    if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
                        SupportTopicAssignment::create([
                            'support_daily_rollup_id' => $conversation->id,
                            'category' => $rule->category,
                            'source' => 'keyword',
                            'confidence' => 1,
                            'reason' => 'keyword:'.$keyword,
                        ]);
                        $matched = true;

                        return;
                    }
                }
            });

        if (! $matched) {
            SupportTopicAssignment::create([
                'support_daily_rollup_id' => $conversation->id,
                'category' => 'uncategorized',
                'source' => 'keyword',
                'confidence' => 0,
                'reason' => 'no keyword match; llm fallback reserved',
            ]);
        }
    }

    /**
     * Текст дня для правил-ключевиков. Хранилище зависит от канала строки
     * (H1837): `telegram` — импортированные сообщения TG-аккаунта, `web` —
     * `chat_messages` треда. Правила категорий общие для обоих каналов —
     * второго набора правил заводить нельзя, иначе одна и та же тема получит
     * две категории в зависимости от того, куда написал студент.
     */
    private function dayText(SupportDailyRollup $conversation): string
    {
        if ($conversation->channel === SupportDailyRollup::CHANNEL_WEB) {
            return ChatMessage::query()
                ->where('support_conversation_id', $conversation->support_conversation_id)
                ->whereDate('created_at', $conversation->conversation_date)
                ->pluck('text')
                ->filter()
                ->implode("\n");
        }

        return TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $conversation->telegram_support_chat_id)
            ->whereDate('sent_at', $conversation->conversation_date)
            ->pluck('text')
            ->filter()
            ->implode("\n");
    }

    /**
     * Normalize a rule's keywords to a trimmed string list. The `keywords => array`
     * cast should already yield an array, but a rule saved through a form that
     * stored the tags as one delimited string comes back as a scalar — defend
     * against that so a mis-stored rule never fatals classification (foreach on a
     * string) nor silently matches nothing.
     *
     * @return array<int, string>
     */
    private function keywordsOf(SupportTopicRule $rule): array
    {
        $keywords = $rule->keywords;

        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n]+/', $keywords) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn ($keyword): string => trim((string) $keyword),
            is_array($keywords) ? $keywords : [],
        ), static fn (string $keyword): bool => $keyword !== ''));
    }
}
