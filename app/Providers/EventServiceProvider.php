<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

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
        \Illuminate\Auth\Events\Login::class => [
            \App\Listeners\UserLoginListener::class,
        ],
        \Illuminate\Auth\Events\Logout::class => [
            \App\Listeners\UserLogoutListener::class,
        ],

        // --- СОЦИАЛЬНАЯ АВТОРИЗАЦИЯ: community-драйверы Socialite ---
        // Google идёт из коробки в laravel/socialite; VK и Yandex регистрируются
        // через событие SocialiteWasCalled (socialiteproviders/*). Конфиг —
        // services.vkontakte / services.yandex.
        \SocialiteProviders\Manager\SocialiteWasCalled::class => [
            \SocialiteProviders\VKontakte\VKontakteExtendSocialite::class.'@handle',
            \SocialiteProviders\Yandex\YandexExtendSocialite::class.'@handle',
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
