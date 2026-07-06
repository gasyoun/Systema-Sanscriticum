<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportMessage;

class SupportTopicClassifier
{
    public function classify(SupportDailyRollup $conversation): void
    {
        $conversation->topicAssignments()->where('source', 'keyword')->delete();

        $text = TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $conversation->telegram_support_chat_id)
            ->whereDate('sent_at', $conversation->conversation_date)
            ->pluck('text')
            ->filter()
            ->implode("\n");

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
