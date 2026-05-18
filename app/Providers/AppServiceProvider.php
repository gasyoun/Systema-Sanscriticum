<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Payment;
use App\Models\Schedule;
use App\Observers\ArticleViewObserver;
use App\Observers\LandingPageObserver;
use App\Observers\PaymentObserver;
use App\Observers\ScheduleObserver;
use App\Observers\SitemapCacheInvalidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \App\Services\Lecture\LectureBuilderClient::class,
            fn () => \App\Services\Lecture\LectureBuilderClient::fromConfig(),
        );
        $this->app->singleton(
            \App\Services\Lecture\LectureAiClient::class,
            fn () => \App\Services\Lecture\LectureAiClient::fromConfig(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // --- 1. ИСПРАВЛЕНИЕ КРИТ 3: Умное включение HTTPS ---
        // Включаем https только если сайт на продакшене (APP_ENV=production)
        // или если мы явно попросили об этом через конфиг.
        if (app()->isProduction() || config('app.force_https', false)) {
            URL::forceScheme('https');
        }

        if (str_contains(config('app.url'), 'trycloudflare.com')) {
            URL::forceScheme('https');
        }
        // ----------------------------------------------------

        // 2. Наблюдатель
        Schedule::observe(ScheduleObserver::class);

        // 3. Локаль
        Carbon::setLocale('ru');

        ArticleView::observe(ArticleViewObserver::class);

        Payment::observe(PaymentObserver::class);

        LandingPage::observe(LandingPageObserver::class);

        // Сброс sitemap.xml-кэша при изменении контента, который туда входит.
        LandingPage::observe(SitemapCacheInvalidator::class);
        Course::observe(SitemapCacheInvalidator::class);
        Article::observe(SitemapCacheInvalidator::class);

        // Мини-блок «Курсы в записи»: главная, legacy-лендинги и builder-блок
        View::composer(['main', 'promo.show', 'promo.legacy', 'promo.blocks.recorded_courses_block'], function ($view): void {
            if (array_key_exists('recordedCoursesMini', $view->getData())) {
                return;
            }

            $courses = Cache::remember('recorded_courses_mini_v2', 300, function () {
                return Course::query()
                    ->where('is_visible', true)
                    ->where('format', 'recorded')
                    ->with([
                        'tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('price'),
                        'categories:id,name,slug,color',
                    ])
                    ->latest('id')
                    ->limit(24)
                    ->get();
            });

            $view->with('recordedCoursesMini', $courses);
        });
    }
}
