<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Course;
use App\Models\DictionaryWord;
use App\Models\LandingPage;
use Illuminate\Http\Response;
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

            $urls[] = [
                'loc' => route('articles.index'),
                'lastmod' => optional(Article::published()->max('updated_at'))
                    ?->format(DATE_ATOM),
                'changefreq' => 'weekly',
                'priority' => '0.7',
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

            Course::where('is_visible', true)
                ->select(['slug', 'updated_at'])
                ->orderBy('updated_at', 'desc')
                ->chunk(500, function ($courses) use (&$urls) {
                    foreach ($courses as $course) {
                        $urls[] = [
                            'loc' => route('shop.course.show', $course->slug),
                            'lastmod' => optional($course->updated_at)->format(DATE_ATOM),
                            'changefreq' => 'weekly',
                            'priority' => '0.8',
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
}
