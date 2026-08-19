<?php

declare(strict_types=1);

namespace App\Services\Discipline;

/**
 * Единственная точка, где контур H2746 отвечает на вопрос «это членство?».
 *
 * Guardrail MG: **пропуск месяца членства и лапс подписки — НИКОГДА не долг.**
 * Курсовой долг считается по блокам курса и тарифам блоков (DebtorsReport);
 * членство живёт в club_memberships и в платежах с тарифом вида
 * `club_3m` / `membership_club_12m`. Пересечение возможно ровно в одном месте —
 * в таблице `payments`, — поэтому фильтр по тарифу вынесен сюда и вызывается
 * везде, где контур смотрит на платежи.
 *
 * Паттерн держим в config/chat_removal.php, а не хардкодом: тарифные коды
 * членства уже менялись (club_* → membership_*_*), и следующее переименование
 * не должно тихо превратить членский платёж в курсовой.
 */
final class MembershipDebtGuard
{
    public static function isMembershipTariff(?string $tariff): bool
    {
        if ($tariff === null || $tariff === '') {
            return false;
        }

        $pattern = (string) config(
            'chat_removal.membership_tariff_pattern',
            '/^(?:club|membership_(?:free|basic|club|top))_\d+m$/',
        );

        return preg_match($pattern, $tariff) === 1;
    }
}
