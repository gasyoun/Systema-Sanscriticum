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
 *
 * H7803: синхронизированные из vendored-пакета правила (`pattern_hash NOT NULL`)
 * несут PCRE-паттерны, а не простые ключевые слова. Их матчинг включается
 * флагом `features.support_topic_synced_rules` (default OFF; flip-процедура в
 * docs/IMPLEMENTATION_SELF_SERVE_WAVE1_2026H2.md). Семантика при включённом
 * флаге — как у reference-движка message-intent-classifier: нормализация
 * текста (mb_lower + ё→е + whitespace fold), правила в порядке priority asc,
 * негация блокирует правило, первый матч выигрывает. Синхронизированный набор
 * идёт ПЕРВЫМ (flip), легаси-ключевые слова остаются фолбэком: на проде
 * легаси-строк нет, поэтому флаг ON = ровно YAML-истина, а в дев-БД с
 * легаси-строками покрытие не падает. Выключенный флаг — прежнее поведение
 * байт-в-байт: synced-строки рантайму не видны.
 */
class SupportTopicClassifier
{
    /** Source назначения для синхронизированного regex-правила (H7803). */
    public const SOURCE_SYNCED = 'synced_rule';

    public function classify(SupportDailyRollup $conversation): void
    {
        $conversation->topicAssignments()
            ->whereIn('source', ['keyword', self::SOURCE_SYNCED])
            ->delete();

        $text = $this->dayText($conversation);
        $matched = false;

        if ((bool) config('features.support_topic_synced_rules', false)) {
            $matched = $this->classifyWithSyncedRules($conversation, $text);
        }

        // Флаг OFF — ровно прежний набор (легаси-ключевые слова).
        // Флаг ON и ни одного regex-матча — тот же набор как фолбэк.
        if (! $matched) {
            $matched = $this->classifyWithLegacyKeywords($conversation, $text);
        }

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
     * H7803: PCRE-матчинг синхронизированных правил. Одно назначение на первое
     * сработавшее правило (priority asc) — семантика reference-движка пакета,
     * по которой измеряется precision ≥0.93. Пустой/битый паттерн в БД не
     * валит классификацию: правило просто не матчится.
     */
    private function classifyWithSyncedRules(SupportDailyRollup $conversation, string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        $normalized = $this->normalize($text);

        $rules = SupportTopicRule::query()
            ->where('is_enabled', true)
            ->whereNotNull('pattern_hash')
            ->where('plane', 'topic')
            ->orderBy('priority')
            ->orderBy('category')
            ->get();

        foreach ($rules as $rule) {
            foreach ($this->listOf($rule->negations) as $negation) {
                if ($this->regexMatches($negation, $normalized)) {
                    continue 2;
                }
            }

            foreach ($this->listOf($rule->keywords) as $pattern) {
                if (! $this->regexMatches($pattern, $normalized)) {
                    continue;
                }

                SupportTopicAssignment::create([
                    'support_daily_rollup_id' => $conversation->id,
                    'category' => $rule->category,
                    'source' => self::SOURCE_SYNCED,
                    'confidence' => 1,
                    'reason' => 'regex:'.$pattern,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Прежний набор: легаси-строки (pattern_hash NULL) с плоским mb_stripos.
     */
    private function classifyWithLegacyKeywords(SupportDailyRollup $conversation, string $text): bool
    {
        $matched = false;

        SupportTopicRule::query()
            ->where('is_enabled', true)
            ->whereNull('pattern_hash')
            ->orderBy('priority')
            ->orderBy('category')
            ->get()
            ->each(function (SupportTopicRule $rule) use ($conversation, $text, &$matched): void {
                foreach ($this->listOf($rule->keywords) as $keyword) {
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

        return $matched;
    }

    /**
     * Нормализация текста — зеркало engine_py/loader.normalize_text и
     * MessageClassifier\Loader::normalizeText: mb_lower + ё→е + ASCII-whitespace
     * fold. Паттерны пакета написаны под нижний регистр.
     */
    private function normalize(string $text): string
    {
        $lowered = mb_strtolower($text, 'UTF-8');
        $folded = str_replace('ё', 'е', $lowered);
        $collapsed = preg_replace('/[\t\n\v\f\r ]+/u', ' ', $folded) ?? '';

        return trim($collapsed);
    }

    /**
     * PCRE-матч в той же форме, что у вендоренного Classifier ('~pattern~u').
     * Битый паттерн в БД (ручная правка, обрезанный sync) — не фатал, а
     * «не матчится»: классификация дня не должна падать из-за строки правила.
     */
    private function regexMatches(string $pattern, string $subject): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        $result = @preg_match('~'.$pattern.'~u', $subject);

        return $result === 1;
    }

    /**
     * Нормализовать правило-список к массиву непустых строк. JSON-cast обычно
     * уже даёт массив, но правило из формы с TagsInput может прийти строкой.
     *
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function listOf($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n]+/', $value) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
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
}
