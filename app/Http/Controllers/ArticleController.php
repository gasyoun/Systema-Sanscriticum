<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\MarketingSetting;
use App\Services\ArticleViewTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ArticleController extends Controller
{
    /**
     * Список статей: /s/
     * Поддерживает фильтрацию по рубрике (?category=slug) и поиск (?q=...).
     */
    public function index(Request $request): View
    {
        // Валидируем входящие параметры — никакого доверия query string
        $validated = $request->validate([
            'q'        => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255', 'exists:article_categories,slug'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        // ── Базовый запрос опубликованных статей ──
        $query = Article::published()
            ->with('category:id,name,slug') // eager load — защита от N+1 в карточках
            ->select([
                // Явный select — не тянем longText body в список
                'id', 'category_id', 'slug', 'title', 'excerpt',
                'cover_path', 'reading_time', 'views_count', 'published_at',
            ])
            ->latest('published_at');

        // ── Фильтр по рубрике ──
        if (!empty($validated['category'])) {
            $query->whereHas('category', function ($q) use ($validated): void {
                $q->where('slug', $validated['category']);
            });
        }

        // ── Поиск (scope на модели, экранирует % и _) ──
        $query->search($validated['q'] ?? null);

        $articles = $query->paginate(9)->withQueryString();

        // ── Сайдбар: рубрики с кол-вом опубликованных статей ──
        $categories = ArticleCategory::query()
            ->withCount('publishedArticles')
            ->having('published_articles_count', '>', 0) // скрываем пустые рубрики
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Общее число опубликованных — для пункта "Все статьи" в сайдбаре
        $totalCount = Article::published()->count();

        return view('articles.index', compact('articles', 'categories', 'totalCount'));
    }

    /**
     * Страница одной статьи: /s/{article:slug}
     * Route model binding автоматически найдёт по slug (см. getRouteKeyName в модели).
     */
    public function show(Article $article, Request $request, ArticleViewTracker $tracker): View
    {
        // Защита: неопубликованные статьи — 404 для всех, кроме админа
        abort_unless(
            $article->is_published
                || (auth()->check() && auth()->user()->is_admin),
            404
        );

        // Трекинг просмотра (не считает админов, ботов и повторные заходы в окне 30 мин).
        // Не считаем черновики, чтобы предпросмотры админа не пачкали статистику.
        if ($article->is_published) {
            $tracker->track($article, $request);
        }

        // Eager load рубрики
        $article->load('category:id,name,slug');

        // Аналитика: ID счётчиков с приоритетом «своё поле > глобальный дефолт»
        $blogAnalytics = $this->resolveAnalytics($article);

        return view('articles.show', compact('article', 'blogAnalytics'));
    }

    /**
     * Определяет ID счётчиков для статьи.
     * Приоритет: поля статьи → глобальные настройки блога.
     *
     * @return array{yandex_id: ?string, vk_id: ?string}
     */
    private function resolveAnalytics(Article $article): array
    {
        $defaults = Cache::remember('blog_analytics_default', 300, function (): array {
            $settings = MarketingSetting::first();

            return [
                'yandex_id' => $settings?->blog_yandex_metrika_id ?: null,
                'vk_id'     => $settings?->blog_vk_pixel_id ?: null,
            ];
        });

        return [
            'yandex_id' => $article->yandex_metrika_id ?: $defaults['yandex_id'],
            'vk_id'     => $article->vk_pixel_id ?: $defaults['vk_id'],
        ];
    }
}