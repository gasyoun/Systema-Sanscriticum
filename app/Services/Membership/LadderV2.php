<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use Carbon\Carbon;

/**
 * Лестница подписей с 01-12-2026 (рулинг 27-11-2026):
 * free → «Базовый», basic → «Оптимальный», club → «Максимальный», top → «Премиум».
 *
 * Внутренние ключи tier НЕ меняются — только подписи и гейты.
 * Дата задаётся конфигом, рубильник позволяет принудительно включить/выключить.
 */
final class LadderV2
{
    public function active(): bool
    {
        $override = config('membership.ladder_v2.override');
        if ($override !== null && $override !== '') {
            return (bool) $override;
        }

        $start = (string) config('membership.ladder_v2.start_date', '2026-12-01');

        return Carbon::now('Europe/Moscow')->toDateString() >= $start;
    }

    public function labelFor(MembershipTier|string $tier): string
    {
        $key = $tier instanceof MembershipTier ? $tier->value : $tier;

        return (string) config('membership.ladder_v2.labels.'.$key, $key);
    }
}
