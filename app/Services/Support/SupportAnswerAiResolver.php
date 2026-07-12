<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Bot\CuratorAi;

/**
 * LLM-резолвер FAQ-суггестера v2 (S5, H816) для категорий D (оплата/цена/
 * тарифы), E (доступ/группа/кабинет), F (материалы/ДЗ/сертификаты) — в
 * отличие от SupportAnswerFactResolver (A/B/C, чистый шаблон без LLM), здесь
 * проверенные LMS-факты студента (курсы/статус обучения/последние платежи —
 * цифры из кода) прокидываются в системный промпт, а ФОРМУЛИРУЕТ ответ
 * CuratorAi::chat() (DeepSeek/OpenRouter). За флагом features.support_ai_assist
 * (off по умолчанию) — выключен, категория опознаётся регексом, но черновик
 * не создаётся. Сам НИЧЕГО не отправляет студенту — только pending-черновик
 * куратору, как и v1 (SupportAnswerSuggester::maybeSuggest).
 */
class SupportAnswerAiResolver
{
    private const LABELS = [
        SupportAnswerSuggestion::CATEGORY_PRICE => 'оплата/цена/тарифы',
        SupportAnswerSuggestion::CATEGORY_ACCESS => 'доступ/группа/личный кабинет',
        SupportAnswerSuggestion::CATEGORY_MATERIALS => 'материалы/ДЗ/сертификаты',
    ];

    public function __construct(private readonly CuratorAi $ai) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_ai_assist');
    }

    /**
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    public function resolve(string $category, User $user, string $question): ?array
    {
        if (! $this->isEnabled() || ! isset(self::LABELS[$category])) {
            return null;
        }

        $facts = $this->lmsFacts($user);

        $systemPrompt = 'Ты — помощник куратора школы. Категория вопроса: '.self::LABELS[$category].'. '
            ."Ниже — проверенные факты из LMS по студенту, используй ТОЛЬКО их для цифр (цены/тарифы/статусы), ничего не выдумывай:\n"
            .$this->factsText($facts)
            ."\n\nПредложи вежливый, конкретный черновик ОТВЕТА студенту на русском. Верни только текст ответа, без пояснений.";

        $answer = $this->ai->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question],
        ]);

        if ($answer === null) {
            return null;
        }

        // Событие `answer_suggested` пишет SupportAnswerSuggester::maybeSuggest() ОДИН
        // раз для всех категорий (SupportAnswerEventLogger) — дублировать здесь нельзя,
        // иначе deflection-метрика (suggested→accepted) задвоится для D/E/F.
        return [
            'draft' => $answer,
            'facts' => $facts,
            // Ниже, чем у фактового резолвера A/B/C (0.85–0.9): ответ формулирует
            // LLM, а не голый шаблон — куратору стоит перечитать перед отправкой.
            'confidence' => 0.6,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lmsFacts(User $user): array
    {
        $courses = $user->courses->map(fn ($course) => [
            'title' => $course->title,
            'status' => $course->pivot->status,
        ])->all();

        $payments = $user->payments()
            ->latest()
            ->limit(5)
            ->get(['tariff', 'status', 'amount', 'course_id'])
            ->map(fn ($payment) => [
                'tariff' => $payment->tariff,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
            ])
            ->all();

        return ['courses' => $courses, 'recent_payments' => $payments];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function factsText(array $facts): string
    {
        $lines = [];
        foreach ($facts['courses'] as $course) {
            $lines[] = "Курс «{$course['title']}», статус обучения: ".($course['status'] ?? '—');
        }
        foreach ($facts['recent_payments'] as $payment) {
            $lines[] = "Платёж: тариф {$payment['tariff']}, статус {$payment['status']}, сумма {$payment['amount']} ₽";
        }

        return $lines === [] ? 'Нет данных об оплатах/курсах.' : implode("\n", $lines);
    }
}
