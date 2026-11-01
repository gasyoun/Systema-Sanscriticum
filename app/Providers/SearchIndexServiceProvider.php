<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Teacher;
use App\Observers\SearchIndexObserver;
use Illuminate\Support\ServiceProvider;

/**
 * H7590 — глобальный поиск в шапке: наблюдатели корпуса (курсы+лендинги,
 * статьи, преподаватели) перегенерируют клиентский индекс public/search.
 *
 * Регистрация вынесена из AppServiceProvider (там наблюдатели живут общим
 * списком, но файл упёрся в token-size-gate 20 КБ) в свой провайдер —
 * поисковая обвязка целиком в одном месте.
 */
class SearchIndexServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        LandingPage::observe(SearchIndexObserver::class);
        Course::observe(SearchIndexObserver::class);
        Article::observe(SearchIndexObserver::class);
        Teacher::observe(SearchIndexObserver::class);
    }
}
