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

        // H4663 (аудит периметра 14-09, п.11): анонимный GET /horizon отдавал
        // 403, чем подтверждал существование дашборда. Гость (или любой
        // не-админ по email-канону) получает 404 — путь не раскрывается;
        // залогиненному не-админу — честный 403.
        Horizon::auth(function ($request) {
            if (Gate::allows('viewHorizon', $request->user())) {
                return true;
            }

            abort($request->user() === null ? 404 : 403);
        });

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
    /**
     * H4663 (аудит периметра 14-09, п.11): см. boot() — Horizon::auth.
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
