<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\MarketingSetting;
use App\Services\ArticleViewTracker;
use App\Support\ArticlesCatalogUrl;
use App\Support\ProductLadderAnchors;
use App\Support\ShopCatalogUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ArticleController extends Controller
{
    /**
     * Список статей: /s/{facets} — рубрика/поиск словами в пути (H3093-паттерн),
     * без query string. Старые ?category=/?q= 301-редиректят на эквивалент;
     * пагинация (?page=) остаётся query-параметром поверх пути.
     */
    public function index(Request $request, ?string $facets = null): View|RedirectResponse
    {
        if ($request->hasAny(['category', 'q'])) {
            $request->validate([
                'q' => ['nullable', 'string', 'max:100'],
                'category' => ['nullable', 'string', 'max:255', 'exists:article_categories,slug'],
            ]);

            $target = ArticlesCatalogUrl::build($request->input('category'), $request->input('q'));
            if ($request->filled('page')) {
                $target .= '?page='.(int) $request->input('page');
            }

            return redirect()->to($target, 301);
        }

        $categorySlug = null;
        $search = null;
        $indexable = true;

        if ($facets !== null) {
            $parsed = ArticlesCatalogUrl::parse($facets);
            abort_if($parsed === null, 404);

            if (isset($parsed['rubrika'])) {
                $categorySlug = $parsed['rubrika'];
                abort_unless(ArticleCategory::where('slug', $categorySlug)->exists(), 404);
            }

            if (isset($parsed['poisk'])) {
                $search = ShopCatalogUrl::decodeWords($parsed['poisk']);
            }

            // Индексируем только «пусто» и «одна рубрика» — поиск (с рубрикой или без)
            // canonical-складывается вниз, как /online/{facets} для формата/уровня/поиска.
            $indexable = array_keys($parsed) === ['rubrika'];
        }

        // canonical всегда складывается до «пусто» или «одна рубрика», даже
        // когда индексируемость (ниже) уже false из-за активного поиска.
        $canonicalPath = ArticlesCatalogUrl::build($categorySlug, null);

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
        if ($categorySlug !== null) {
            $query->whereHas('category', function ($q) use ($categorySlug): void {
                $q->where('slug', $categorySlug);
            });
        }

        // ── Поиск (scope на модели, экранирует % и _) ──
        $query->search($search);

        $articles = $query->paginate(9)->withQueryString();

        // ── Сайдбар: рубрики с кол-вом опубликованных статей ──
        // whereHas, не having(withCount-алиас) — HAVING без GROUP BY на алиас
        // валит SQLite (тестовая БД), хотя MySQL это терпит молча.
        $categories = ArticleCategory::query()
            ->whereHas('publishedArticles') // скрываем пустые рубрики
            ->withCount('publishedArticles')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Общее число опубликованных — для пункта "Все статьи" в сайдбаре
        $totalCount = Article::published()->count();

        return view('articles.index', compact(
            'articles', 'categories', 'totalCount', 'categorySlug', 'search', 'canonicalPath', 'indexable'
        ));
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

        // CTA-«лесенка» в конце статьи (H387): честные якоря цен из общего
        // хелпера — те же числа, что на «С чего начать» и в «Материалах».
        $ladder = ProductLadderAnchors::resolve();

        return view('articles.show', compact('article', 'blogAnalytics', 'ladder'));
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
                'vk_id' => $settings?->blog_vk_pixel_id ?: null,
            ];
        });

        return [
            'yandex_id' => $article->yandex_metrika_id ?: $defaults['yandex_id'],
            'vk_id' => $article->vk_pixel_id ?: $defaults['vk_id'],
        ];
    }
}
