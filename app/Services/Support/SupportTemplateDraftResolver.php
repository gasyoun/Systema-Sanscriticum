<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Support\MessagePlaceholders;

/**
 * Шаблонный резолвер черновиков ПЕРЕД LLM-суггестером (H1838, тикет S9).
 * Для категорий D/E/F с устоявшимся ответом черновик собирается из привязанного
 * шаблона {@see MessageTemplate} (колонка suggester_category) с подстановкой
 * {@see MessagePlaceholders} — LLM выпадает из цикла там, где
 * ответ шаблонизируем. Непривязанная категория проваливается в прежний
 * LLM-путь ({@see SupportLlmDraftComposer}) без изменений.
 *
 * Ничего не отправляет — только готовит текст куратору (как весь суггестер).
 * Каждый собранный черновик пишет событие answer_template_drafted — зеркало
 * answer_llm_drafted, чтобы принимаемость шаблонных и LLM-черновиков
 * сравнивалась по одному журналу (метрика успеха S9). В дневной LLM-cap
 * шаблонные черновики не входят: внешний вызов не совершался.
 */
class SupportTemplateDraftResolver
{
    /**
     * Шаблон — курируемый человеком готовый ответ: увереннее LLM-формулировки
     * (0.6–0.7), но без живой персонализации фактов A/B/C (1.0 там не бывает).
     */
    private const CONFIDENCE = 0.8;

    public function isEnabled(): bool
    {
        return (bool) config('features.support_template_drafts');
    }

    /**
     * Черновик из привязанного шаблона или null (нет привязки / флаг OFF) —
     * тогда вызывающий идёт прежним LLM-путём.
     *
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    public function resolve(string $category, User $user): ?array
    {
        if (! $this->isEnabled() || ! in_array($category, SupportAnswerSuggestion::LLM_CATEGORIES, true)) {
            return null;
        }

        /** @var MessageTemplate|null $template */
        $template = MessageTemplate::query()
            ->boundToSuggesterCategory($category)
            ->orderByDesc('updated_at')
            ->first();

        if ($template === null) {
            return null;
        }

        // Подстановка без курса: {course}/{block} схлопываются в пустую строку,
        // {pay_link} ведёт в личный кабинет — ровно как в MessageTemplate::render.
        $draft = $template->render($user);

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAnswerEventLogger::EVENT_TEMPLATE_DRAFTED,
            'meta' => [
                'feature' => 'support_answer_suggester_template',
                'category' => $category,
                'user_id' => $user->id,
                'template_id' => $template->id,
                'preview' => mb_substr($draft, 0, 240),
            ],
        ]);

        return [
            'draft' => $draft,
            'facts' => [
                'type' => 'template',
                'template_id' => $template->id,
                'template_title' => $template->title,
            ],
            'confidence' => self::CONFIDENCE,
        ];
    }
}
