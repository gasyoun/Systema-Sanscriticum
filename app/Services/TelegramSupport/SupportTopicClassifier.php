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
                foreach ($rule->keywords ?? [] as $keyword) {
                    if ($keyword !== '' && mb_stripos($text, (string) $keyword) !== false) {
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
}
