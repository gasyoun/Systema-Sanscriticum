<?php

namespace App\Providers;

use App\Auth\GuestChatUser;
use App\Models\CourseWaitlistItem;
use App\Policies\CourseWaitlistItemPolicy;
use App\Policies\MediaPolicy;
// use Illuminate\Support\Facades\Gate;
use App\Support\GuestChat;
use Awcodes\Curator\Models\Media;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Media::class => MediaPolicy::class,
        CourseWaitlistItem::class => CourseWaitlistItemPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Гостевой guard живого веб-чата (H536 Phase 2): резолвит непостоянную
        // GuestChatUser из session-токена. Используется приватным broadcast-каналом
        // support.conversation.{id} наряду с web-guard'ом — так анонимный посетитель
        // авторизуется без строки в users. /broadcasting/auth идёт под web-middleware,
        // поэтому сессия (и токен) на запросе доступны.
        Auth::viaRequest('chat_guest', function (Request $request): ?GuestChatUser {
            $token = $request->hasSession() ? $request->session()->get(GuestChat::SESSION_KEY) : null;

            return is_string($token) && $token !== '' ? new GuestChatUser($token) : null;
        });
    }
}
