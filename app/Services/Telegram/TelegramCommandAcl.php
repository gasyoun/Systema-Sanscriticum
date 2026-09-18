<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Log;

/**
 * H4253: резолвер автора Telegram-команды в панельного пользователя.
 *
 * Рuling MG 06-09 («what teacher can do, managers and admin can do as well»):
 * права определяются РОЛЬЮ панельного User, а не отдельным whitelist'ом.
 *  - super_admin / admin / manager — любые группы (managesAll);
 *  - teacher — только свои группы (User.teacher_id → курсы → Group::ledBy);
 *  - неопознанный отправитель — отказ беззвучно, только лог.
 *
 * Путь резолва: users.telegram_id (быстрый), затем social_accounts
 * (provider=telegram) — канонический стор консолидации идентичностей.
 * Устаревший whitelist zapisi_cancel_admin_ids (H4199) продолжает работать
 * только для бездатовой reply-команды «Отмена занятия» и здесь не читается.
 */
final class TelegramCommandAcl
{
    /**
     * @return array{user: User, role: string, teacher: ?Teacher}|null
     */
    public function resolve(int $telegramUserId): ?array
    {
        $user = self::findUser($telegramUserId);

        if ($user === null) {
            Log::info('TelegramCommandAcl: sender is not a panel user, ignored', [
                'telegram_user_id' => $telegramUserId,
            ]);

            return null;
        }

        return [
            'user' => $user,
            'role' => (string) $user->role,
            'teacher' => $user->teacher_id !== null ? Teacher::find($user->teacher_id) : null,
        ];
    }

    /**
     * Пользователь кабинета по Telegram user id (любая роль, без логов):
     * users.telegram_id, затем social_accounts (provider=telegram). Нужен и
     * там, где автор — студент (голоса в опросах бота), а не панельный staff.
     */
    public static function findUser(int $telegramUserId): ?User
    {
        return User::query()
            ->where('telegram_id', $telegramUserId)
            ->first()
            ?? User::query()
                ->whereHas('socialAccounts', function ($query) use ($telegramUserId) {
                    $query->where('provider', SocialAccount::PROVIDER_TELEGRAM)
                        ->where('provider_id', (string) $telegramUserId);
                })
                ->first();
    }

    /**
     * Роль управляет всеми группами (staff), а не только своими.
     */
    public static function managesAll(string $role): bool
    {
        return in_array($role, [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER], true);
    }
}
