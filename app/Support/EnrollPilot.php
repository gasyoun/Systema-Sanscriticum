<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Пилот записи через личку (features.enroll_pilot_telegram_ids, 21-10-2026).
 *
 * Список пуст — пропускаем всех; непуст — только перечисленные Telegram ID.
 * Нужен, чтобы проверить на проде на своём аккаунте, не задевая студентов.
 */
final class EnrollPilot
{
    /** @param  iterable<int|string|null>  $telegramIds  любой из аккаунтов человека */
    public static function allows(iterable $telegramIds): bool
    {
        $pilot = array_map('strval', (array) config('features.enroll_pilot_telegram_ids', []));
        if ($pilot === []) {
            return true;
        }

        foreach ($telegramIds as $id) {
            if ($id !== null && $id !== '' && in_array((string) $id, $pilot, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Пилот задан И человек в нём. Только тогда ветка записи может обойти
     * общий переключатель аккаунта auto_reply_enabled: он включает все
     * автоответы бота сразу, а для проверки нужен один человек.
     *
     * @param  iterable<int|string|null>  $telegramIds
     */
    public static function listed(iterable $telegramIds): bool
    {
        return (array) config('features.enroll_pilot_telegram_ids', []) !== [] && self::allows($telegramIds);
    }
}
