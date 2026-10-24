<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Group;
use App\Models\Tariff;
use App\Models\TelegramSupportContact;

/**
 * Чекаут-ссылка «блок N именно этой группы» (20-10-2026).
 *
 * Группа едет в query (?group=&gsig=) и подписана HMAC от (tariff, group) на
 * APP_KEY: студент не может переписать её на чужую группу (другой чат,
 * другое расписание). Подпись проверяется дважды — на странице чекаута и
 * авторитетно в PaymentController при создании платежа; плюс группа обязана
 * принадлежать курсу тарифа.
 *
 * 21-10-2026: контакт лички без привязки к кабинету едет рядом (?tgc=&csig=,
 * подпись от tariff+group+contact). После оплаты контакт привязывается к
 * плательщику — так ссылка-заявка в чат находит дорогу обратно в личку.
 */
final class EnrollmentGroupLink
{
    public static function url(Tariff $tariff, Group $group, ?TelegramSupportContact $contact = null): string
    {
        $params = [
            'tariff' => $tariff->id,
            'group' => $group->id,
            'gsig' => self::signature((int) $tariff->id, (int) $group->id),
        ];

        if ($contact !== null) {
            $params['tgc'] = $contact->id;
            $params['csig'] = self::contactSignature((int) $tariff->id, (int) $group->id, (int) $contact->id);
        }

        return route('checkout.show', $params);
    }

    public static function signature(int $tariffId, int $groupId): string
    {
        return substr(hash_hmac('sha256', "enroll-group:{$tariffId}:{$groupId}", (string) config('app.key')), 0, 32);
    }

    public static function contactSignature(int $tariffId, int $groupId, int $contactId): string
    {
        return substr(hash_hmac('sha256', "enroll-contact:{$tariffId}:{$groupId}:{$contactId}", (string) config('app.key')), 0, 32);
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

    /** Контакт лички из подписанных параметров (только вместе с уже проверенной группой). */
    public static function resolveContact(Tariff $tariff, Group $group, mixed $contactId, mixed $signature): ?TelegramSupportContact
    {
        if (! is_numeric($contactId) || ! is_string($signature) || $signature === '') {
            return null;
        }

        if (! hash_equals(self::contactSignature((int) $tariff->id, (int) $group->id, (int) $contactId), $signature)) {
            return null;
        }

        return TelegramSupportContact::query()->find((int) $contactId);
    }
}
