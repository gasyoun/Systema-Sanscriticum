<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;

/**
 * H5083 — ремедиация находки аудита H5046 claim-trusted-autopaid-session-
 * bootstrap: auto-trust заявки (сразу paid, без ручной сверки) обязан
 * опираться на доказательство СУЩЕСТВУЮЩЕГО проверенного ученика, а не на
 * голый факт сессии (auth()->check()).
 *
 * Почему сессии недостаточно: resolveUser() публичных форм оплаты mintит
 * НОВЫЙ аккаунт и логинит его в той же сессии (bootstrap). Уже на ВТОРОМ
 * POST той же сессии auth()->check() истинен — прежний предикат создавал
 * auto-trusted paid-платеж без единой сверки (self-minted bootstrap).
 *
 * Доверие теперь требует одного из:
 *  1. хотя бы один PAID-платеж этого пользователя (статус из
 *     Payment::PAID_STATUSES — проверенный денежный контур: вебхук
 *     эквайринга с подписью, ручная сверка админом, подтвержденный
 *     прошлый claim);
 *  2. возраст аккаунта ≥ trust_min_account_age_hours (по умолчанию 24ч) —
 *     аккаунт, самозагруженный в этой же сессии, в окно не попадает.
 *
 * Конфиг-неймспейс передается вызывающим контроллером ('paypal' /
 * 'bank_claim'), чтобы порог окна настраивался отдельно на канал.
 */
final class ClaimTrustPolicy
{
    public const DEFAULT_MIN_ACCOUNT_AGE_HOURS = 24;

    public function isPreexistingVerified(User $user, string $configNamespace): bool
    {
        $hasPaid = Payment::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', Payment::PAID_STATUSES)
            ->exists();

        if ($hasPaid) {
            return true;
        }

        $minAgeHours = max(
            0,
            (int) config($configNamespace.'.trust_min_account_age_hours', self::DEFAULT_MIN_ACCOUNT_AGE_HOURS),
        );

        $createdAt = $user->created_at;

        return $createdAt !== null && $createdAt->lte(now()->subHours($minAgeHours));
    }
}
