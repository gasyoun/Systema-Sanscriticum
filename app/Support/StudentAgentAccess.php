<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MembershipTier;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

/**
 * H5193 — единственный вопрос «пущен ли этот студент к bounded student agent».
 *
 * MG 20-09: агент включается (STUDENT_AGENT_ENABLED=true) только вместе с
 * гейтом «дополнительная платная подписка» — отвечать агент может платному
 * члену клуба. Предикат тот же, что у RecordingAccessPolicy для открытых
 * лекций: действующее членство с ПЛАТНЫМ уровнем (Basic и выше — Club /
 * Standard / Professional / Top). Уровень Free (гостевая регистрация H3643,
 * гранты уснувшим H2915) и отсутствие периода — отказ, 403 на маршруте.
 *
 * Без VIP-исключений и админ-обходов: ruling говорит «ON for paying
 * subscribers», и никакого другого списка в гейт не вписано. Семантика
 * MEMBERSHIP_TIERED=false наследуется от ClubEntitlement::activeTierFor
 * («любой живой период = Club»), как и в RecordingAccessPolicy.
 */
final class StudentAgentAccess
{
    public static function featureOn(): bool
    {
        return (bool) config('features.student_agent', false);
    }

    public static function canUse(?User $user): bool
    {
        if (! self::featureOn() || $user === null) {
            return false;
        }

        return self::isPayingSubscriber($user);
    }

    /** Платное членство: любой действующий период с уровнем не ниже Basic. */
    public static function isPayingSubscriber(User $user): bool
    {
        $tier = app(ClubEntitlement::class)->activeTierFor($user);

        return $tier instanceof MembershipTier && $tier !== MembershipTier::Free;
    }
}
