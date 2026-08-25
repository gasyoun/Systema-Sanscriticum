<?php

namespace App\Services\TelegramSupport;

use App\Models\ChatMessage;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\SupportTopicRule;
use App\Models\TelegramSupportMessage;

/**
 * Keyword-классификация топиков дневного rollup'а. С H1837 (S10) работает для
 * ОБОИХ каналов: правила (`SupportTopicRule`) и назначения одни и те же, меняется
 * только хранилище, из которого берётся текст дня (см. {@see self::dayText()}).
 */
class SupportTopicClassifier
{
    public function classify(SupportDailyRollup $conversation): void
    {
        $conversation->topicAssignments()->where('source', 'keyword')->delete();

        $text = $this->dayText($conversation);

        $matched = false;

        // H3529 (помеченный дефолт, run-log волны): синхронизированные из
        // пакета правила (pattern_hash NOT NULL) хранятся в БД как staging,
        // но рантайму пока НЕ видны — текущий матчинг это плоский mb_stripos,
        // а 7 YAML-паттернов не содержат метасимволов («техподдержк»,
        // «не работает», «кабинет», «нет доступа», «можно попробовать») и при
        // включении изменили бы живую классификацию до прогона через гейт
        // precision ≥93% (VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md).
        // Переключение = отдельное решение той волны.
        SupportTopicRule::query()
            ->where('is_enabled', true)
            ->whereNull('pattern_hash')
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
     * Текст всех сообщений субъекта за день — из того хранилища, которому
     * принадлежит канал строки. Таблицы не сливаются: это две ветки чтения, а не
     * общий запрос.
     */
    private function dayText(SupportDailyRollup $conversation): string
    {
        if ($conversation->isTelegramSide()) {
            return TelegramSupportMessage::query()
                ->where('telegram_support_chat_id', $conversation->telegram_support_chat_id)
                ->whereDate('sent_at', $conversation->conversation_date)
                ->pluck('text')
                ->filter()
                ->implode("\n");
        }

        $query = ChatMessage::query()
            ->whereDate('created_at', $conversation->conversation_date);

        // Канал живёт в chat_messages.source (H1200): NULL == веб-виджет.
        match ($conversation->channel) {
            SupportDailyRollup::CHANNEL_VK => $query->where('source', 'vk'),
            SupportDailyRollup::CHANNEL_TELEGRAM_BOT => $query->where('source', 'telegram_bot'),
            default => $query->where(fn ($sub) => $sub->whereNull('source')
                ->orWhereNotIn('source', ['vk', 'telegram_bot'])),
        };

        if ($conversation->support_conversation_id !== null) {
            $query->where('support_conversation_id', $conversation->support_conversation_id);
        } elseif ($conversation->web_user_id !== null) {
            $query->where('user_id', $conversation->web_user_id)->whereNull('support_conversation_id');
        } else {
            $query->whereNull('support_conversation_id')->whereNull('user_id');
        }

        return $query->pluck('text')->filter()->implode("\n");
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
