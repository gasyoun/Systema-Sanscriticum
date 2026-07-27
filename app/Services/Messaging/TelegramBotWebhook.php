<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Один бот в реестре вебхуков: чем регистрироваться и куда.
 *
 * $problem заполнен, когда бота нельзя регистрировать (нет токена или секрета) —
 * такой показывается в обзоре, но пропускается при перерегистрации. Регистрация
 * без секрета опаснее отсутствия регистрации: вебхук выглядит живым, а
 * fail-closed middleware отбивает каждый апдейт 403, и снаружи это неотличимо от
 * молчания.
 */
final class TelegramBotWebhook
{
    /**
     * @param  list<string>  $allowedUpdates
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $username,
        public readonly string $token,
        public readonly string $secret,
        public readonly string $url,
        public readonly array $allowedUpdates,
        public readonly ?string $problem = null,
    ) {}

    public function isRegisterable(): bool
    {
        return $this->problem === null;
    }

    /**
     * Есть ли чем спрашивать Telegram. Без токена запрос бессмысленен — такого
     * бота показываем с его проблемой, но в сеть не ходим.
     */
    public function hasToken(): bool
    {
        return $this->token !== '';
    }
}
