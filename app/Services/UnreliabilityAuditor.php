<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Авто-выставление флага «неблагонадёжный» и подсветка «поведение исправилось».
 *
 * Правила:
 *  - 3 и более просроченных (status=expired) обещаний за последние 12 мес. →
 *    is_unreliable=true (с пометкой unreliable_auto=true).
 *  - Флаг автоматически НЕ снимается. Если за последние 6 мес. у студента
 *    нет ни одной просрочки — выставляем discipline_improved_since=today,
 *    чтобы менеджер видел подсказку и мог снять флаг вручную.
 */
class UnreliabilityAuditor
{
    public const AUTO_THRESHOLD = 3;

    public const WINDOW_MONTHS = 12;

    public const IMPROVED_WINDOW_MONTHS = 6;

    public function recountFor(User $user): bool
    {
        $since = Carbon::now()->subMonths(self::WINDOW_MONTHS);

        $expiredCount = PaymentPromise::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentPromise::STATUS_EXPIRED)
            ->where('promised_at', '>=', $since)
            ->count();

        if ($expiredCount >= self::AUTO_THRESHOLD && ! $user->isUnreliable()) {
            $user->markUnreliable(
                reason: "Автофлаг: {$expiredCount} просроченных обещаний за последние 12 месяцев.",
                by: null,
                auto: true,
            );

            return true;
        }

        return false;
    }

    public function recountDisciplineImproved(User $user): void
    {
        if (! $user->isUnreliable()) {
            return;
        }

        $since = Carbon::now()->subMonths(self::IMPROVED_WINDOW_MONTHS);

        $recentTrouble = PaymentPromise::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentPromise::STATUS_EXPIRED)
            ->where('promised_at', '>=', $since)
            ->exists();

        if ($recentTrouble) {
            // Поведение не исправилось — если ранее ставили улучшение, снимаем.
            if ($user->discipline_improved_since !== null) {
                $user->forceFill(['discipline_improved_since' => null])->save();
            }

            return;
        }

        if ($user->discipline_improved_since === null) {
            $user->forceFill(['discipline_improved_since' => Carbon::now()->startOfDay()])->save();
        }
    }
}
