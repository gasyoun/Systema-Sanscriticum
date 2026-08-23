<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * H3312: доступ только у адреса из единого канона
     * config('services.admin.email') (env ADMIN_EMAIL). Пусто -> fail-closed:
     * никому (включая любые исторические захардкоженные адреса), с warning
     * в лог и без исключений.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $adminEmail = trim((string) config('services.admin.email'));

            if ($adminEmail === '') {
                Log::warning('viewHorizon denied: ADMIN_EMAIL is not configured.');

                return false;
            }

            return $user !== null && $user->email === $adminEmail;
        });
    }
}
