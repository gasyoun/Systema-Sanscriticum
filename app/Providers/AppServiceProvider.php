<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL; // <--- Важно!
use Illuminate\Support\Carbon;
use App\Models\Schedule;
use App\Observers\ScheduleObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. ЖЕСТКОЕ ВКЛЮЧЕНИЕ HTTPS (без условий if)
        URL::forceScheme('https');

        // 2. Наблюдатель
        Schedule::observe(ScheduleObserver::class);

        // 3. Локаль
        Carbon::setLocale('ru');
    }
}
