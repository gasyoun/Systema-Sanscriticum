<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

use App\Support\OnboardingChecklist;

/**
 * Подготовленное действие привязки бота (H6186, роль 19) — ТОЛЬКО данные.
 *
 * «Безопасная подготовка привязки» миссии H6186: шаги чек-листа и пара
 * deep-link/токен существуют как чистые данные для человека-оператора
 * (первые 30 дней human-send, H6183). Никакого применения здесь и никогда:
 *
 *  - привязку выполняет только человек через канонические точки:
 *    выдача — TelegramController::start() (POST /telegram/connect),
 *    применение — TelegramWebhookController, ветка `/start {token}`;
 *  - `applied` жёстко false — «binding tokens are data, never applied»;
 *  - deep link содержит reusable token, поэтому НИКОГДА не попадает в черновик
 *    (LLM), toObservation() (отчёт golden-прогона) или evidence-отчёт
 *    (H6186: «любой reusable token не попадёт в отчёт или LLM»);
 *  - fingerprint (sha256 от токена) — необратимая метка для сопоставления
 *    действия с выдачей без переноса plaintext.
 */
final readonly class W1BotBindingPrepAction
{
    /** Привязка никогда не применяется автоматически (инвариант миссии). */
    public const APPLIED = false;

    /** Действие выдаётся только человеку-оператору (human-send per H6183). */
    public const FOR_HUMAN_OPERATOR_ONLY = true;

    /**
     * @param  list<array{key: string, label: string, hint: string}>  $checklistSteps
     *                                                                                 шаги строго из утверждённого чек-листа привязки
     *                                                                                 ({@see OnboardingChecklist::bindingSteps()});
     * @param  string|null  $deepLink  deep link как данные («t.me/{bot}?start={token}»,
     *                                 формат и источник имени бота — как в TelegramController::start());
     *                                 НЕ в отчёт и НЕ в LLM;
     * @param  string|null  $tokenFingerprint  sha256-hex от токена — метка без plaintext;
     * @param  bool  $tokenRenewed  true, когда вместо истёкшего токена подготовлен свежий
     */
    public function __construct(
        public array $checklistSteps,
        public ?string $deepLink,
        public ?string $tokenFingerprint,
        public bool $tokenRenewed,
    ) {}

    /** @return array<string, mixed> безопасное наблюдение БЕЗ токена и deep link */
    public function toSafeArray(): array
    {
        return [
            'checklist_steps' => $this->checklistSteps,
            'token_renewed' => $this->tokenRenewed,
            'has_deep_link' => $this->deepLink !== null,
            'has_token_fingerprint' => $this->tokenFingerprint !== null,
            'applied' => self::APPLIED,
            'for_human_operator_only' => self::FOR_HUMAN_OPERATOR_ONLY,
        ];
    }
}
