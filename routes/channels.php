<?php

use App\Support\SupportChannelAuth;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * Приватный per-conversation канал живого веб-чата поддержки (H536).
 * Авторизует и залогиненного пользователя (guard `web`), и анонимного гостя
 * (guard `chat_guest` по session-токену) единым предикатом SupportChannelAuth:
 *  - гость — совпадение guest_token треда;
 *  - студент — владелец треда;
 *  - оператор-админ — любой тред (Helpdesk, Phase 5).
 */
Broadcast::channel('support.conversation.{conversationId}', function ($user, $conversationId) {
    return SupportChannelAuth::canAccess($user, (int) $conversationId);
}, ['guards' => ['web', 'chat_guest']]);
