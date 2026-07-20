<?php

namespace App\Providers;

use App\Listeners\LogFailedAuthentication;
use App\Listeners\UserLoginListener;
use App\Listeners\UserLogoutListener;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\VKontakte\VKontakteExtendSocialite;
use SocialiteProviders\Yandex\YandexExtendSocialite;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // --- ТРЕКИНГ АКТИВНОСТИ ---
        Login::class => [
            UserLoginListener::class,
        ],
        Logout::class => [
            UserLogoutListener::class,
        ],

        // --- ЛЕНТА «ПРОБЛЕМ СО ВХОДОМ» (H849) ---
        // Неудачный Auth::attempt на /login и /shop/login → запись в access_attempts.
        Failed::class => [
            LogFailedAuthentication::class,
        ],

        // --- СОЦИАЛЬНАЯ АВТОРИЗАЦИЯ: community-драйверы Socialite ---
        // Google идёт из коробки в laravel/socialite; VK и Yandex регистрируются
        // через событие SocialiteWasCalled (socialiteproviders/*). Конфиг —
        // services.vkontakte / services.yandex.
        SocialiteWasCalled::class => [
            VKontakteExtendSocialite::class.'@handle',
            YandexExtendSocialite::class.'@handle',
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
