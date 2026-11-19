<?php

declare(strict_types=1);

namespace App\Services\Support\HumanSend;

use App\Models\User;

/**
 * H6183 (пакет hermes-24-01a10bca): доказательство, что send разрешил человек.
 *
 * Сервис human-send отказывается отправлять что-либо без этого объекта с
 * НЕ-агентским kind: «только аутентифицированное действие человека разрешает
 * send» — агентский вызов (CLI, джоб, дренер) получает `agent()` и отказ
 * без единой записи. Носитель нельзя подделать «просто передав id»: web-вид
 * создаётся только из живого auth()-пользователя страницы, telegram-вид — из
 * tapper, которого SupportHintSendButton уже проверил получателем подсказок
 * или админом.
 */
final class HumanSendAuthorization
{
    /** Аутентифицированный куратор/админ в Filament (очередь черновиков). */
    public const KIND_WEB_USER = 'web_user';

    /** Нажавший кнопку в Telegram: проверен получателем подсказок/админом. */
    public const KIND_TELEGRAM_TAPPER = 'telegram_tapper';

    /** Машина: джоб, дренер, CLI. Отправлять не имеет права НИКОГДА. */
    public const KIND_AGENT = 'agent';

    private function __construct(
        private readonly string $kind,
        private readonly ?int $userId,
        private readonly ?string $telegramId,
    ) {}

    public static function webUser(User $user): self
    {
        return new self(self::KIND_WEB_USER, $user->id, null);
    }

    public static function telegramTapper(string $telegramId, ?int $userId): self
    {
        return new self(self::KIND_TELEGRAM_TAPPER, $userId, $telegramId);
    }

    public static function agent(): self
    {
        return new self(self::KIND_AGENT, null, null);
    }

    public function isHuman(): bool
    {
        return $this->kind !== self::KIND_AGENT;
    }

    /** id юзера-человека для resolved_by (tapper может быть без users-записи). */
    public function userId(): ?int
    {
        return $this->userId;
    }

    /** Машиночитаемое описание актёра — в meta события и в логи отказа. */
    public function describe(): array
    {
        $description = ['kind' => $this->kind];

        if ($this->userId !== null) {
            $description['user_id'] = $this->userId;
        }

        if ($this->telegramId !== null) {
            $description['telegram_id'] = $this->telegramId;
        }

        return $description;
    }
}
