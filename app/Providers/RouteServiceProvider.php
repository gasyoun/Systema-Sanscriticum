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
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
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
