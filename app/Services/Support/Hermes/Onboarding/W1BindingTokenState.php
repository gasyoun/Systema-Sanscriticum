<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

/**
 * Состояние токена привязки бота (H6186, Epic E026, Hermes W1, роль 19).
 *
 * Канонический механизм привязки — `users.telegram_auth_token`
 * (Str::random(32), выдача в TelegramController::start(), погашение в
 * TelegramWebhookController, ветка `/start {token}`): токен одноразовый и
 * пересоздаётся при каждой выдаче (ротация). Этот enum — чистая проверка
 * состояния предъявленного токена БЕЗ применения привязки:
 *
 *  - none    — токен не предъявлен;
 *  - active  — совпадает с живым токеном аккаунта, аккаунт ещё не привязан;
 *  - expired — отозван ротацией (аккаунт не привязан, живой токен уже иной)
 *              либо помечен истёкшим вышестоящей пробой (synthetic-контекст);
 *  - replayed — повторное использование после привязки/погашения (replay):
 *              привязка уже выполнена, токен предъявлен снова — отказ;
 *  - unknown — состояние доказать нельзя (нет сверки с аккаунтом) —
 *              без уверенного угадывания, человеку.
 *
 * Сравнение — hash_equals (constant-time): токен — capability-секрет, даже
 * проверка его состояния не должна подсказывать совпадение по времени.
 * Токен здесь — входные данные; ни одно значение enum не хранит и не
 * возвращает plaintext (H6186: reusable token не попадает в отчёт или LLM).
 */
enum W1BindingTokenState: string
{
    case NONE = 'none';

    case ACTIVE = 'active';

    case EXPIRED = 'expired';

    case REPLAYED = 'replayed';

    case UNKNOWN = 'unknown';

    /**
     * Состояние из машиночитаемого контекста события (probe-результат
     * вышестоящей проверки либо synthetic-фикстура). Неизвестное значение
     * нормализуется в unknown — никакого угадывания.
     */
    public static function fromContext(array $context): self
    {
        $raw = $context['binding_token_state'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return self::NONE;
        }

        return self::tryFrom($raw) ?? self::UNKNOWN;
    }

    /**
     * Чистая классификация по фактам об аккаунте (без I/O): предъявленный
     * токен, текущий живой токен аккаунта и признак уже выполненной привязки.
     *
     * Порядок проверок (безопасность прежде всего):
     *  1. ничего не предъявлено → none;
     *  2. аккаунт уже привязан, токен предъявлен → replayed (повтор);
     *  3. совпадает с живым токеном непривязанного аккаунта → active;
     *  4. живой токен иной → expired (прежний отозван ротацией выдачи);
     *  5. сверить не с чем → unknown.
     */
    public static function classify(string $claimed, ?string $current, bool $alreadyBound): self
    {
        if ($claimed === '') {
            return self::NONE;
        }

        if ($alreadyBound) {
            return self::REPLAYED;
        }

        if ($current !== null && $current !== '' && hash_equals($current, $claimed)) {
            return self::ACTIVE;
        }

        if ($current !== null && $current !== '') {
            return self::EXPIRED;
        }

        return self::UNKNOWN;
    }

    /** Истёкший токен — подлежит обновлению как данные (не применяется здесь). */
    public function isExpired(): bool
    {
        return $this === self::EXPIRED;
    }

    /** Replay — запрещённый контур доступа: отказ, без черновика и токена. */
    public function isReplay(): bool
    {
        return $this === self::REPLAYED;
    }

    /** Состояние доказать нельзя — человеку, без уверенного угадывания. */
    public function isUnverifiable(): bool
    {
        return $this === self::UNKNOWN;
    }
}
