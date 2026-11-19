<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

use App\Support\OnboardingChecklist;
use Illuminate\Support\Str;

/**
 * Генератор данных привязки (H6186, роль 19): deep-link/binding-token как
 * чистые данные («deep-link/binding-token generation as pure data»).
 *
 * Ничего не выдаётся в БД и не применяется: каноническая выдача остаётся в
 * TelegramController::start() (users.telegram_auth_token, Str::random(32),
 * deep link «https://t.me/{bot}?start={token}», имя бота из
 * config('services.telegram.student_bot_username') ?: bot_username), а
 * применение — в TelegramWebhookController, ветка `/start {token}`. Здесь
 * соблюдаются ровно формат токена и URL той выдачи (проверяется тестом), а
 * результат живёт только в действии для человека-оператора (H6183).
 *
 * Истёкший токен никогда не переиспользуется: при EXPIRED готовится свежий
 * (tokenRenewed=true). Fingerprint (sha256) — необратимая метка для
 * сопоставления без переноса plaintext.
 */
final class W1BotBindingTokenIssuer
{
    /**
     * Подготовить действие-данные: шаги утверждённого чек-листа привязки
     * ({@see OnboardingChecklist::bindingSteps()}) + пара deep link/токен.
     */
    public function issue(W1BindingTokenState $tokenState): W1BotBindingPrepAction
    {
        $token = Str::random(32);

        $botUsername = (string) (config('services.telegram.student_bot_username')
            ?: config('services.telegram.bot_username'));

        return new W1BotBindingPrepAction(
            checklistSteps: OnboardingChecklist::bindingSteps(),
            deepLink: "https://t.me/{$botUsername}?start={$token}",
            tokenFingerprint: hash('sha256', $token),
            tokenRenewed: $tokenState->isExpired(),
        );
    }
}
