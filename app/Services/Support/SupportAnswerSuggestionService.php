<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAnswerSuggestion;
use App\Models\User;

/**
 * Разрешение pending-черновика FAQ-суггестера куратором: Принять / Изменить /
 * Отклонить (H247). Единый сервис и для инлайн-баннера Helpdesk, и для любой
 * будущей очереди-ресурса. Каждый переход пишет событие в SupportAiReplyEvent
 * (метрика дефлекшна S3). Бот НИЧЕГО не отправляет — статус лишь фиксирует выбор.
 */
class SupportAnswerSuggestionService
{
    /** Куратор берёт черновик как есть (текст уходит в поле ответа Helpdesk). */
    public function accept(SupportAnswerSuggestion $suggestion, ?User $curator): void
    {
        $this->resolve($suggestion, SupportAnswerSuggestion::STATUS_ACCEPTED, $curator, SupportAnswerEventLogger::EVENT_ACCEPTED);
    }

    /** Куратор правит черновик перед отправкой. */
    public function edit(SupportAnswerSuggestion $suggestion, ?User $curator): void
    {
        $this->resolve($suggestion, SupportAnswerSuggestion::STATUS_EDITED, $curator, SupportAnswerEventLogger::EVENT_EDITED);
    }

    /** Куратор отклоняет черновик. */
    public function discard(SupportAnswerSuggestion $suggestion, ?User $curator): void
    {
        $this->resolve($suggestion, SupportAnswerSuggestion::STATUS_DISCARDED, $curator, SupportAnswerEventLogger::EVENT_DISCARDED);
    }

    private function resolve(SupportAnswerSuggestion $suggestion, string $status, ?User $curator, string $eventType): void
    {
        if ($suggestion->status !== SupportAnswerSuggestion::STATUS_PENDING) {
            return;
        }

        $suggestion->update([
            'status' => $status,
            'resolved_by' => $curator?->id,
            'resolved_at' => now(),
        ]);

        SupportAnswerEventLogger::log($suggestion, $eventType);
    }
}
