<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Bot\CuratorAi;

/**
 * FAQ-суггестер v2 (S5): черновик ответа для категорий D (оплата/цена/тарифы),
 * E (доступ/группа/кабинет), F (материалы/ДЗ/сертификаты). В отличие от
 * шаблонного {@see SupportAnswerFactResolver} (A/B/C, без LLM), здесь ЦИФРЫ
 * берутся из кода LMS (тариф через Tariff::calculateFinalPriceForUser — НЕ
 * пересчитываем руками), а внешний LLM (CuratorAi/OpenRouter) лишь ФОРМУЛИРУЕТ
 * из них вежливый черновик. Ничего не отправляет — только готовит текст куратору.
 *
 * Три страховки:
 *  - флаг features.support_ai_assist (иначе категория опознана, но черновик не
 *    строится — LLM выключен);
 *  - дневной cap вызовов (MarketingSetting.support_ai_daily_cap → дефолт
 *    config('features.support_ai_daily_cap')), считается по answer_llm_drafted;
 *  - приватность: приватный TEXT импортированного Telegram-ЛС попадает в промпт
 *    ТОЛЬКО при features.support_ai_include_telegram (факты LMS — всегда).
 */
class SupportLlmDraftComposer
{
    public function __construct(private readonly CuratorAi $ai) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_ai_assist');
    }

    /**
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    public function compose(string $category, User $user, string $questionText, string $sourceType): ?array
    {
        if (! $this->isEnabled() || ! in_array($category, SupportAnswerSuggestion::LLM_CATEGORIES, true)) {
            return null;
        }

        $grounding = $this->facts($category, $user);
        if ($grounding === null) {
            // Категория опознана, но фактов в LMS нет (нет группы/тарифа/материалов)
            // — пустой черновик куратору не показываем (как в A/B/C).
            return null;
        }

        if ($this->capReached()) {
            return null;
        }

        $result = $this->ai->chatWithUsage([
            ['role' => 'system', 'content' => $this->systemPrompt($category)],
            ['role' => 'user', 'content' => $this->userPrompt($category, $grounding['facts'], $questionText, $sourceType)],
        ]);

        $draft = $result['content'];
        if ($draft === null) {
            // Нет ключа / ошибка провайдера / пустой ответ — черновик не создаётся.
            return null;
        }

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAnswerEventLogger::EVENT_LLM_DRAFTED,
            'meta' => [
                'feature' => 'support_answer_suggester_llm',
                'category' => $category,
                'user_id' => $user->id,
                'model' => $result['model'],
                'usage' => $result['usage'],
                'preview' => mb_substr($draft, 0, 240),
            ],
        ]);

        return [
            'draft' => $draft,
            'facts' => $grounding['facts'],
            'confidence' => $grounding['confidence'],
        ];
    }

    /** Достигнут ли дневной предел LLM-вызовов. 0/отрицательный → без предела. */
    private function capReached(): bool
    {
        $cap = MarketingSetting::cached()?->support_ai_daily_cap;
        if ($cap === null) {
            $cap = (int) config('features.support_ai_daily_cap', 100);
        }

        if ($cap <= 0) {
            return false;
        }

        $today = SupportAiReplyEvent::query()
            ->where('event_type', SupportAnswerEventLogger::EVENT_LLM_DRAFTED)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return $today >= $cap;
    }

    /**
     * Факты LMS для категории. null, если фактов нет (черновик не строим).
     *
     * @return array{facts: array<string, mixed>, confidence: float}|null
     */
    private function facts(string $category, User $user): ?array
    {
        return match ($category) {
            SupportAnswerSuggestion::CATEGORY_PAYMENT => $this->paymentFacts($user),
            SupportAnswerSuggestion::CATEGORY_ACCESS => $this->accessFacts($user),
            SupportAnswerSuggestion::CATEGORY_MATERIALS => $this->materialsFacts($user),
            default => null,
        };
    }

    /** D — тарифы курсов активных групп студента с ФИНАЛЬНОЙ ценой из кода. */
    private function paymentFacts(User $user): ?array
    {
        $tariffs = [];
        foreach ($user->activeGroups as $group) {
            foreach ($group->courses as $course) {
                foreach ($course->tariffs()->where('is_active', true)->get() as $tariff) {
                    $tariffs[$tariff->id] = [
                        'course' => $course->title,
                        'tariff' => $tariff->title,
                        // ЕДИНСТВЕННЫЙ источник истины по цене (лояльность/депозит/upgrade).
                        'final_price' => $tariff->calculateFinalPriceForUser($user),
                    ];
                }
            }
        }

        if ($tariffs === []) {
            return null;
        }

        return ['facts' => ['type' => 'payment', 'tariffs' => array_values($tariffs)], 'confidence' => 0.7];
    }

    /** E — активные группы студента и курсы этих групп. */
    private function accessFacts(User $user): ?array
    {
        $groups = $user->activeGroups->map(fn ($group): array => [
            'group' => $group->name,
            'courses' => $group->courses->pluck('title')->values()->all(),
        ])->values()->all();

        if ($groups === []) {
            return null;
        }

        return ['facts' => ['type' => 'access', 'groups' => $groups], 'confidence' => 0.6];
    }

    /** F — сколько опубликованных уроков/материалов доступно студенту. */
    private function materialsFacts(User $user): ?array
    {
        $published = Lesson::query()
            ->forUserGroups($user)
            ->where('is_published', true)
            ->count();

        if ($published === 0) {
            return null;
        }

        return ['facts' => ['type' => 'materials', 'published_lessons' => $published], 'confidence' => 0.6];
    }

    private function systemPrompt(string $category): string
    {
        $topic = match ($category) {
            SupportAnswerSuggestion::CATEGORY_PAYMENT => 'вопрос про оплату/цену/тарифы',
            SupportAnswerSuggestion::CATEGORY_ACCESS => 'вопрос про доступ/группу/личный кабинет',
            SupportAnswerSuggestion::CATEGORY_MATERIALS => 'вопрос про материалы/ДЗ/сертификаты',
            default => 'вопрос студента',
        };

        return 'Ты — помощник куратора школы санскрита. Студент задал '.$topic.'. '
            .'Составь вежливый, конкретный черновик ОТВЕТА студенту на русском, опираясь СТРОГО '
            .'на приведённые факты (JSON). НЕ выдумывай цифры, ссылки и сроки, которых нет в фактах. '
            .'Если факт для точного ответа отсутствует — предложи уточнить у куратора. '
            .'Верни только текст ответа, без пояснений.';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function userPrompt(string $category, array $facts, string $questionText, string $sourceType): string
    {
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = "Факты LMS (JSON):\n".$factsJson;

        // Приватность: сырой текст импортированного Telegram-ЛС уходит во внешний
        // LLM только при явном разрешении. Иначе LLM формулирует из одних фактов.
        $includeText = $sourceType !== SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE
            || (bool) config('features.support_ai_include_telegram');

        if ($includeText && trim($questionText) !== '') {
            $prompt .= "\n\nВопрос студента:\n".$questionText;
        }

        return $prompt;
    }
}
