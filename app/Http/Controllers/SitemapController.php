<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\DictionaryWord;
use App\Models\LandingPage;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function index(): Response
    {
        // Кэш на час — боты ходят часто, а свежесть с разбегом до часа для sitemap приемлема.
        $xml = Cache::remember('sitemap.xml', now()->addHour(), function (): string {
            $urls = [];

            $urls[] = [
                'loc' => url('/'),
                'lastmod' => optional(LandingPage::where('is_active', true)->max('updated_at'))
                    ?->format(DATE_ATOM),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ];

            $urls[] = [
                'loc' => route('shop.index'),
                'lastmod' => optional(Course::where('is_visible', true)->max('updated_at'))
                    ?->format(DATE_ATOM),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ];

            // Категории каталога — единственная facet-комбинация с index,follow
            // (см. ShopController::index); остальные /online/{facets} noindex.
            Category::where('is_visible', true)
                ->select(['slug', 'updated_at'])
                ->orderBy('slug')
                ->chunk(500, function ($categories) use (&$urls) {
                    foreach ($categories as $category) {
                        $urls[] = [
                            'loc' => route('shop.index.facets', ['facets' => 'kategoriya/'.$category->slug]),
                            'lastmod' => optional($category->updated_at)->format(DATE_ATOM),
                            'changefreq' => 'weekly',
                            'priority' => '0.6',
                        ];
                    }
                });

            $urls[] = [
                'loc' => route('shop.pathway'),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];

            $urls[] = [
                'loc' => route('hub.sanskritorium'),
                'changefreq' => 'monthly',
                'priority' => '0.5',
            ];

            $urls[] = [
                'loc' => route('articles.index'),
                'lastmod' => optional(Article::published()->max('updated_at'))
                    ?->format(DATE_ATOM),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];

            // Рубрики статей — единственная facet-комбинация /s/{facets} с
            // index,follow (см. ArticleController::index); поиск — noindex.
            // whereHas, не having(withCount-алиас) — валит SQLite без GROUP BY.
            ArticleCategory::query()
                ->whereHas('publishedArticles')
                ->select(['slug', 'updated_at'])
                ->orderBy('slug')
                ->chunk(500, function ($categories) use (&$urls) {
                    foreach ($categories as $category) {
                        $urls[] = [
                            'loc' => route('articles.index.facets', ['facets' => 'rubrika/'.$category->slug]),
                            'lastmod' => optional($category->updated_at)->format(DATE_ATOM),
                            'changefreq' => 'weekly',
                            'priority' => '0.5',
                        ];
                    }
                });

            // Карточки /koloda (публичная проба SRS без входа) — три языковых
            // страницы, ни одна не сворачивается (одна facet-размерность).
            if (config('srs.enabled')) {
                $urls[] = ['loc' => route('srs.index'), 'changefreq' => 'weekly', 'priority' => '0.5'];
                $urls[] = ['loc' => route('srs.index.lang', ['word' => 'hindi']), 'changefreq' => 'weekly', 'priority' => '0.4'];
                $urls[] = ['loc' => route('srs.index.lang', ['word' => 'vse']), 'changefreq' => 'weekly', 'priority' => '0.4'];
            }

            // Публичный реестр сертификатов/справок (один URL, без query-вариантов).
            $urls[] = [
                'loc' => route('certificate.registry'),
                'lastmod' => optional(Certificate::max('issued_at'))
                    ? Carbon::parse(Certificate::max('issued_at'))->format(DATE_ATOM)
                    : null,
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];

            LandingPage::where('is_active', true)
                ->where('is_listed', true)
                ->select(['slug', 'updated_at'])
                ->orderBy('updated_at', 'desc')
                ->chunk(500, function ($pages) use (&$urls) {
                    foreach ($pages as $page) {
                        $urls[] = [
                            'loc' => url('/'.$page->slug),
                            'lastmod' => optional($page->updated_at)->format(DATE_ATOM),
                            'changefreq' => 'weekly',
                            'priority' => '0.8',
                        ];
                    }
                });

            PrivateArchiveEligibility::scopePublic(Course::where('is_visible', true))
                ->withOwnCatalogCard()
                ->select(['slug', 'format', 'updated_at'])
                ->orderBy('updated_at', 'desc')
                ->chunk(500, function ($courses) use (&$urls) {
                    foreach ($courses as $course) {
                        $urls[] = [
                            'loc' => route('shop.course.show', $course->slug),
                            'lastmod' => optional($course->updated_at)->format(DATE_ATOM),
                            'changefreq' => 'weekly',
                            'priority' => self::coursePriority($course),
                        ];
                    }
                });

            Article::published()
                ->select(['slug', 'updated_at', 'published_at'])
                ->orderBy('published_at', 'desc')
                ->chunk(500, function ($articles) use (&$urls) {
                    foreach ($articles as $article) {
                        $urls[] = [
                            'loc' => route('articles.show', $article->slug),
                            'lastmod' => optional($article->updated_at ?? $article->published_at)
                                ?->format(DATE_ATOM),
                            'changefreq' => 'monthly',
                            'priority' => '0.6',
                        ];
                    }
                });

            // ───── Словарные entity-страницы (SEO P2 / H204) ─────
            // Chunk БУДЕТ построен, но URL-ы слов ВЫДАЮТСЯ в sitemap только когда
            // index_enabled (Wave 1+). Wave 0 (default): держим их вне карты сайта
            // («built but unsubmitted», решение D2). Одна каноническая запись на slug.
            if (config('dictionary_seo.index_enabled', false)) {
                $urls[] = [
                    'loc' => route('slovar.index'),
                    'lastmod' => optional(DictionaryWord::max('updated_at'))?->format(DATE_ATOM),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ];

                DictionaryWord::query()
                    ->whereNotNull('slug')
                    ->whereHas('dictionary', fn ($q) => $q->where('is_active', true))
                    ->select(['slug', 'translation', 'updated_at'])
                    ->orderBy('slug')
                    ->chunk(1000, function ($words) use (&$urls) {
                        $seen = [];
                        foreach ($words as $word) {
                            if (isset($seen[$word->slug]) || ! $word->isIndexable()) {
                                continue; // одна канон. запись на slug + только curated-core (D1/D3)
                            }
                            $seen[$word->slug] = true;
                            $urls[] = [
                                'loc' => route('slovar.show', $word->slug),
                                'lastmod' => optional($word->updated_at)->format(DATE_ATOM),
                                'changefreq' => 'monthly',
                                'priority' => '0.4',
                            ];
                        }
                    });
            }

            return view('sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    /**
     * H2893 / H2-15: donate 0.3, recorded 0.6, live (and unknown) 0.8.
     * Donate wins on the slug even if format is recorded.
     */
    public static function coursePriority(Course $course): string
    {
        if (str_contains(mb_strtolower((string) $course->slug), 'donat')) {
            return '0.3';
        }

        if ($course->format === 'recorded') {
            return '0.6';
        }

        return '0.8';
    }
}
