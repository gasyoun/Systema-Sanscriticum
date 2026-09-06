<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Group;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * H4253: ACL для новых TG-команд препода (каникулы / дата-cancel в чате
 * группы) — SocialAccount(provider=telegram) -> User -> роль.
 *
 * super_admin/admin/manager управляют любой группой; teacher — только своими
 * (User.teacher_id -> Course -> Group::ledBy). Неизвестный telegram-аккаунт
 * или роль без доступа к группам — отказ, только в лог (бот не отвечает
 * нарушителю в чате). Отдельно от zapisi_cancel_admin_ids whitelist (H4199) —
 * тот путь («Отмена занятия» reply на пост-напоминание) этим резолвером
 * не гейтится и не меняется.
 */
final class TelegramGroupAcl
{
    public static function canManageGroup(int $telegramUserId, int $groupId): bool
    {
        $user = self::resolveUser($telegramUserId);

        if ($user === null) {
            Log::info('TelegramGroupAcl: no user mapped to this telegram account, refused', [
                'telegram_user_id' => $telegramUserId,
                'group_id' => $groupId,
            ]);

            return false;
        }

        if ($user->isAdminLike() || $user->isManager()) {
            return true;
        }

        if ($user->isTeacher() && $user->teacher_id !== null) {
            $allowed = Group::query()->whereKey($groupId)->ledBy((int) $user->teacher_id)->exists();

            if (! $allowed) {
                Log::info('TelegramGroupAcl: teacher has no access to this group, refused', [
                    'telegram_user_id' => $telegramUserId,
                    'user_id' => $user->id,
                    'teacher_id' => $user->teacher_id,
                    'group_id' => $groupId,
                ]);
            }

            return $allowed;
        }

        Log::info('TelegramGroupAcl: role has no group-management access, refused', [
            'telegram_user_id' => $telegramUserId,
            'user_id' => $user->id,
            'role' => $user->role,
            'group_id' => $groupId,
        ]);

        return false;
    }

    public static function resolveUser(int $telegramUserId): ?User
    {
        $account = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_TELEGRAM)
            ->where('provider_id', (string) $telegramUserId)
            ->first();

        return $account?->user;
    }
}
