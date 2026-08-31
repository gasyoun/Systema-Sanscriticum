<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Course;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Generated /llms.txt (SAMSKRTE-SEO-H2 W3).
 * Live catalog, not a committed dump. Cache hour matches sitemap.xml.
 */
class LlmsTxtController extends Controller
{
    public const CACHE_KEY = 'llms.txt';

    public function index(): Response
    {
        $body = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): string {
            return $this->render();
        });

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function render(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        if ($origin === '') {
            $origin = 'https://samskrte.ru';
        }

        $lines = [
            '# Общество ревнителей санскрита',
            '# '.$origin,
            '#',
            '# Не путать с https://samskrtam.ru (архив / биография).',
            '# Цитировать: /s/zachem-sanskrit, /k/grammatika-po-kocerginoi-gr42, /online',
            '# Биография: https://samskrtam.ru/marcis-gasuns/',
            '# Не цитировать как статьи: словарные /slovar/{slug}, кабинет, чекаут, /course/.',
            '#',
            '# Surfaces',
            $origin.'/',
            $origin.'/online',
            $origin.'/slovar',
        ];

        $articles = Article::published()
            ->orderBy('published_at')
            ->get(['slug', 'title']);

        foreach ($articles as $article) {
            $lines[] = $origin.'/s/'.$article->slug;
        }

        $lines[] = '#';
        $lines[] = '# Courses (is_visible)';

        $courses = Course::query()
            ->withOwnCatalogCard()
            ->where('is_visible', true)
            ->orderBy('title')
            ->get(['title', 'slug', 'format']);

        foreach ($courses as $course) {
            $format = $course->format !== null && $course->format !== ''
                ? (string) $course->format
                : 'unknown';
            $title = str_replace(["\r", "\n", "\t"], ' ', (string) $course->title);
            $lines[] = $title."\t".$origin.'/k/'.$course->slug."\t".$format;
        }

        return implode("\n", $lines)."\n";
    }
}
