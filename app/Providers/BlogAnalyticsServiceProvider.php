<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class BlogAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Прокидываем ID счётчиков по умолчанию во все view блога.
        // Контроллер статьи может переопределить их через ->with('blogAnalytics', ...).
        View::composer('layouts.articles', function ($view): void {
            // Если контроллер уже передал blogAnalytics — не трогаем
            if (array_key_exists('blogAnalytics', $view->getData())) {
                return;
            }

            $analytics = Cache::remember('blog_analytics_default', 300, function () {
                $settings = MarketingSetting::first();

                return [
                    'yandex_id' => $settings?->blog_yandex_metrika_id ?: null,
                    'vk_id'     => $settings?->blog_vk_pixel_id ?: null,
                ];
            });

            $view->with('blogAnalytics', $analytics);
        });
    }
}