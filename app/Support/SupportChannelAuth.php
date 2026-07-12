<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\GuestChatUser;
use App\Models\SupportConversation;
use App\Models\User;

/**
 * Единственный источник правды авторизации приватного канала
 * `support.conversation.{id}` (H536). Вынесен из routes/channels.php, чтобы
 * тестироваться юнит-тестом в отрыве от запущенного Reverb-сервера.
 *
 * Три класса подписчиков:
 *  - гость (`GuestChatUser`): токен совпадает с `guest_token` треда;
 *  - студент (`User`): владелец треда (`user_id`);
 *  - оператор (`User` с ролью админа): слушает любой тред (Helpdesk, Phase 5).
 */
final class SupportChannelAuth
{
    public static function canAccess(User|GuestChatUser|null $user, int $conversationId): bool
    {
        if ($user instanceof GuestChatUser) {
            return SupportConversation::query()
                ->whereKey($conversationId)
                ->where('guest_token', $user->getAuthIdentifier())
                ->exists();
        }

        if ($user instanceof User) {
            // Оператор поддержки (админ/супер-админ) слушает любой тред.
            if ($user->isAdminLike()) {
                return true;
            }

            // Студент — только собственный тред.
            return SupportConversation::query()
                ->whereKey($conversationId)
                ->where('user_id', $user->id)
                ->exists();
        }

        return false;
    }
}
