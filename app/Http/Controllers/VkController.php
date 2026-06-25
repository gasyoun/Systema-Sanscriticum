<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;

class VkController extends Controller
{
    /**
     * Привязка VK-бота к аккаунту через одноразовый неугадываемый токен.
     *
     * Раньше ссылка несла сырой `?ref={user_id}` — последовательный, угадываемый
     * id, по которому VkBotController делал `User::find($ref)`. Это IDOR: любой
     * мог привязать свой VK к чужому (ещё не привязанному) аккаунту, перебрав id.
     * Теперь, как у telegram_auth_token, выдаём `Str::random(32)` и в вебхуке
     * ищём по нему (см. VkBotController::handle), а после привязки — гасим.
     */
    public function connect()
    {
        $user = auth()->user();

        $token = Str::random(32);

        $user->update([
            'vk_auth_token' => $token,
        ]);

        // ?ref передаётся VK сообществу как payload первого входящего сообщения.
        $groupId = config('services.vk.group_id');
        $url = "https://vk.me/club{$groupId}?ref={$token}";

        return redirect()->away($url);
    }
}
