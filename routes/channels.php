<?php

use App\Models\ChatMessage;
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
 * Private per-conversation channel for the live support chat widget (H536).
 * Phase 1 scaffold: an authenticated user may listen to a conversation that
 * carries a message of theirs. Guest-visitor authorization (Phase 2 guest
 * identity) and the rate-limited public post endpoint (Phase 3) extend this —
 * a guest token will authorize its own conversation without a `users` row.
 */
Broadcast::channel('support.conversation.{conversationId}', function ($user, $conversationId) {
    return ChatMessage::query()
        ->where('support_conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});
