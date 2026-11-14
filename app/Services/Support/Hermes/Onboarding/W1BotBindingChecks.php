<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

use App\Models\User;

/**
 * Проверки привязки бота (H6186, роль 19): принадлежность аккаунта, чужой
 * account, истёкший token и replay — чистая логика поверх контекста события
 * плюс read-only сверка с аккаунтом. Никаких мутаций и отправок: привязка
 * не применяется, токены не пишутся («binding tokens are data, never applied»).
 *
 * Контекст (машиночитаемый контракт события):
 *  - third_party_account: true — явное требование привязки чужого аккаунта;
 *  - author_telegram_id, account_owner_telegram_id — факты принадлежности
 *    (совпадение → confirmed; расхождение → чужой account; нет фактов →
 *    unverified — без угадывания, решение за человеком);
 *  - binding_state: unlinked|unknown|failing_repeatedly — состояние привязки
 *    (golden-фикстура H6181); неизвестное значение нормализуется в unknown;
 *  - binding_token_state: active|expired|replayed|unknown — probe-результат
 *    состояния токена (synthetic/вышестоящая проверка);
 *  - claimed_link_token, claimed_user_id — предъявленный токен и аккаунт для
 *    read-only сверки ({@see W1BindingTokenState::classify()}); plaintext
 *    токена дальше контекста не уходит.
 */
final readonly class W1BotBindingChecks
{
    private function __construct(
        public string $ownership,
        public bool $foreignAccount,
        public W1BindingTokenState $tokenState,
        public ?string $bindingState,
    ) {}

    /** @param  array<string, mixed>  $context */
    public static function fromContext(array $context): self
    {
        return new self(
            ownership: self::checkOwnership($context),
            foreignAccount: ($context['third_party_account'] ?? false) === true
                || self::checkOwnership($context) === 'mismatch',
            tokenState: self::checkTokenState($context),
            bindingState: self::bindingState($context),
        );
    }

    /**
     * Принадлежность аккаунта: совпадают ли Telegram-аккаунт автора и
     * аккаунт владельца кабинета. Фактов нет → unverified (без угадывания).
     *
     * @param  array<string, mixed>  $context
     */
    private static function checkOwnership(array $context): string
    {
        $author = $context['author_telegram_id'] ?? null;
        $owner = $context['account_owner_telegram_id'] ?? null;

        if ($author === null || $owner === null) {
            return 'unverified';
        }

        return (string) $author === (string) $owner ? 'confirmed' : 'mismatch';
    }

    /**
     * Состояние предъявленного токена привязки: probe-результат из контекста
     * (binding_token_state) либо read-only сверка с аккаунтом
     * (claimed_link_token + claimed_user_id). Plaintext не возвращается.
     *
     * @param  array<string, mixed>  $context
     */
    private static function checkTokenState(array $context): W1BindingTokenState
    {
        if (is_string($context['binding_token_state'] ?? null)
            && ($context['binding_token_state']) !== '') {
            return W1BindingTokenState::fromContext($context);
        }

        $claimed = (string) ($context['claimed_link_token'] ?? '');
        if ($claimed === '') {
            return W1BindingTokenState::NONE;
        }

        $userId = $context['claimed_user_id'] ?? null;
        if ($userId === null) {
            // Токен предъявлен, но сверить не с чем — без уверенного угадывания.
            return W1BindingTokenState::UNKNOWN;
        }

        /** @var User|null $user */
        $user = User::query()->find($userId); // read-only, без мутаций
        if ($user === null) {
            return W1BindingTokenState::UNKNOWN;
        }

        return W1BindingTokenState::classify($claimed, $user->telegram_auth_token, $user->telegram_id !== null);
    }

    /**
     * Состояние привязки из контекста: unlinked|unknown|failing_repeatedly.
     * Неизвестное значение — как unknown (без уверенного угадывания).
     *
     * @param  array<string, mixed>  $context
     */
    private static function bindingState(array $context): ?string
    {
        $state = $context['binding_state'] ?? null;

        if (! is_string($state) || $state === '') {
            return null;
        }

        return in_array($state, ['unlinked', 'unknown', 'failing_repeatedly'], true) ? $state : 'unknown';
    }

    public function isReplay(): bool
    {
        return $this->tokenState->isReplay();
    }

    public function isExpiredToken(): bool
    {
        return $this->tokenState->isExpired();
    }

    public function isUnverifiable(): bool
    {
        return $this->tokenState->isUnverifiable();
    }

    /** Чужой account: контур доступа, отказ без черновика и токена. */
    public function isForeignAccount(): bool
    {
        return $this->foreignAccount;
    }

    /**
     * Безопасный снимок для результата/отчётов — без токенов и PII.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ownership' => $this->ownership,
            'foreign_account' => $this->foreignAccount,
            'token_state' => $this->tokenState->value,
            'replay_detected' => $this->isReplay(),
            'expired_token' => $this->isExpiredToken(),
            'binding_state' => $this->bindingState,
        ];
    }
}
