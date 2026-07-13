<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;

/**
 * Единая точка записи событий FAQ-суггестера в общий журнал SupportAiReplyEvent
 * (H247). Метрика дефлекшна (S3): доля suggested → accepted меряется именно этими
 * событиями. Для TG-источника проставляем telegram_support_message_id (колонка
 * nullable), для веб-чата — null, а контекст всегда лежит в meta.
 */
class SupportAnswerEventLogger
{
    public const EVENT_SUGGESTED = 'answer_suggested';

    public const EVENT_ACCEPTED = 'answer_accepted';

    public const EVENT_EDITED = 'answer_edited';

    public const EVENT_DISCARDED = 'answer_discarded';

    // Один вызов внешнего LLM ради черновика D/E/F (S5). Отдельно от
    // EVENT_SUGGESTED (та метрика — дефлекшн), чтобы дневной cap считался по
    // фактическим LLM-вызовам, а не по всем суггестам.
    public const EVENT_LLM_DRAFTED = 'answer_llm_drafted';

    public static function log(SupportAnswerSuggestion $suggestion, string $eventType): void
    {
        $telegramMessageId = $suggestion->source_type === SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE
            ? $suggestion->source_id
            : null;

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $telegramMessageId,
            'event_type' => $eventType,
            'meta' => [
                'feature' => 'support_answer_suggester',
                'suggestion_id' => $suggestion->id,
                'category' => $suggestion->category,
                'source_type' => $suggestion->source_type,
                'source_id' => $suggestion->source_id,
                'user_id' => $suggestion->user_id,
            ],
        ]);
    }
}
