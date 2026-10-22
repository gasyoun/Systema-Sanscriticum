<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Group;
use App\Models\Tariff;

/**
 * Чекаут-ссылка «блок N именно этой группы» (20-10-2026).
 *
 * Группа едет в query (?group=&gsig=) и подписана HMAC от (tariff, group) на
 * APP_KEY: студент не может переписать её на чужую группу (другой чат,
 * другое расписание). Подпись проверяется дважды — на странице чекаута и
 * авторитетно в PaymentController при создании платежа; плюс группа обязана
 * принадлежать курсу тарифа.
 */
final class EnrollmentGroupLink
{
    public static function url(Tariff $tariff, Group $group): string
    {
        return route('checkout.show', [
            'tariff' => $tariff->id,
            'group' => $group->id,
            'gsig' => self::signature((int) $tariff->id, (int) $group->id),
        ]);
    }

    public static function signature(int $tariffId, int $groupId): string
    {
        return substr(hash_hmac('sha256', "enroll-group:{$tariffId}:{$groupId}", (string) config('app.key')), 0, 32);
    }

    /**
     * Группа из подписанных параметров, если подпись верна и группа — группа
     * курса этого тарифа. Иначе null (чекаут идёт как обычно, без группы).
     */
    public static function resolve(Tariff $tariff, mixed $groupId, mixed $signature): ?Group
    {
        if (! is_numeric($groupId) || ! is_string($signature) || $signature === '' || $tariff->course_id === null) {
            return null;
        }

        if (! hash_equals(self::signature((int) $tariff->id, (int) $groupId), $signature)) {
            return null;
        }

        return Group::query()
            ->whereKey((int) $groupId)
            ->whereHas('courses', fn ($q) => $q->where('courses.id', $tariff->course_id))
            ->first();
    }
}
