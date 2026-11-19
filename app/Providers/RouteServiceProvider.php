<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Вебхуки с проверкой подписи на маршруте (routes/api.php: verify.vk.bot,
     * verify.vk.magnet, verify.tg.bot, verify.tg.magnet, verify.max.magnet) —
     * вне общего api-лимита. Платёжные/Zoom-вебхуки сюда НЕ входят: у них свой
     * throttle на маршруте.
     *
     * @var list<string>
     */
    public const SIGNED_MESSENGER_WEBHOOKS = [
        'api/vk-webhook',
        'api/webhooks/vk-magnet',
        'api/telegram/webhook',
        'api/webhooks/telegram-magnet',
        'api/webhooks/telegram-magnet/*',
        'api/webhooks/max-magnet/*',
    ];

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Вебхуки мессенджеров с проверкой подписи/секрета (verify.* на
            // маршруте) общим IP-лимитом не режем: ВК шлёт события с горстки
            // своих адресов, и массовая рассылка даёт ~2000 событий в минуту.
            // Лимит 60/мин отбивал их 429 — 07.10 и 13.10.2026 потеряно
            // ~15 тыс. событий (ответы учеников на рассылку в том числе).
            // Чужие запросы отсекает подпись, а не лимит.
            if ($request->is(...self::SIGNED_MESSENGER_WEBHOOKS)) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // H5087 (remediation of H5046): POST /livewire/update регистрируется
        // вендором Livewire только с группой web — на уровне маршрута его не
        // затроттлить. Анонимам — жёсткий IP-лимит (реплей публичных
        // компонентов гонит SQL-веер на каждый хит); залогиненным — запас под
        // Filament-UI (дебаунс поиска, таблицы). Применяет
        // ThrottleLivewireUpdates (в web-группе, только путь livewire/update).
        RateLimiter::for('livewire-update', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(240)->by('lw-u:'.$request->user()->id)
                : Limit::perMinute(30)->by('lw-ip:'.$request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
